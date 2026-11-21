<?php

namespace App\Actions;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class RouteConverseDraft
{
    private const array REPLY_SELECTION_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'source_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 2],
            'asset_id' => ['type' => ['integer', 'null']],
            'ask_follow_up' => ['type' => 'boolean'],
            'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
        ],
        'required' => ['confidence'],
        'additionalProperties' => false,
    ];

    public function __construct(private ConverseAiSettings $configuration, private RecordConverseAiCall $recordCall) {}

    /** @return array{text: string, provider: string, model: string, fallback: bool, workflow?: string, workflow_agent?: string}|null */
    public function __invoke(string $instructions, string $input, bool $localOnly = true, ?string $preferred = null, ?callable $accept = null, ?string $localFallback = null, ?string $localModel = null, ?int $localBudget = null, ?string $cloudInput = null, bool $complex = false, bool $cloudOnly = false, ?string $cloudProvider = null, array $workflowContext = [], ?callable $recordAttempt = null, array $callContext = []): ?array
    {
        $settings = $this->configuration->get();
        $local = array_values(array_unique(array_filter([
            $localModel ?? $settings['ollama_model'],
            $localFallback ?? $settings['ollama_fallback_model'],
            $settings['ollama_fallback_model'],
        ], fn (?string $model): bool => filled($model))));
        $cloud = $localOnly || $settings['allow_cloud'] !== true ? [] : $this->cloudCandidates($settings, $complex, $cloudProvider);
        $cloudFirst = $cloudInput === null && ($preferred ?? $settings['primary']) === 'openai';
        $local = $settings['ollama_enabled'] && ! $cloudOnly
            ? array_map(fn (string $model): array => ['ollama', $model, null], array_slice($local, 0, 2)) : [];
        $candidates = $cloudFirst ? [...$cloud, ...$local] : [...$local, ...$cloud];
        $workflow = $workflowContext === [] ? null : collect($settings['workflows'])->first(
            fn (array $rule): bool => array_all($rule['when'], fn (string $value, string $field): bool => $value
                === ($field === 'complexity' ? ($complex ? 'complex' : 'standard') : ($workflowContext[$field] ?? null))),
        );
        if ($workflow !== null) {
            $candidates = $this->workflowCandidates($workflow['steps'], $settings, $workflowContext, $localOnly, $cloudOnly, $cloudProvider);
        }

        $eligible = array_filter($candidates, fn (array $candidate): bool => ! isset($candidate[4]));
        $localCount = count(array_filter($eligible, fn (array $candidate): bool => $candidate[0] === 'ollama'));
        $remaining = ['local' => $localCount, 'cloud' => count($eligible) - $localCount];
        $budget = ['local' => (float) ($localBudget ?? 36), 'cloud' => 21.0];
        $localConnectionFailed = false;
        foreach ($candidates as $index => $candidate) {
            [$provider, $model, $key] = $candidate;
            $agentId = $candidate[3] ?? $workflowContext['agent'] ?? null;
            $attempt = ['provider' => $provider, 'model' => $model, 'agent' => $agentId, 'workflow' => $workflow['name'] ?? null];
            if (isset($candidate[4])) {
                if ($recordAttempt !== null) {
                    $recordAttempt([...$attempt, 'status' => 'skipped', 'reason' => $candidate[4]]);
                }

                continue;
            }
            $group = $provider === 'ollama' ? 'local' : 'cloud';
            $left = $remaining[$group]--;
            if ($group === 'local' && $localConnectionFailed && $remaining['cloud'] > 0 && $workflow === null) {
                if ($recordAttempt !== null) {
                    $recordAttempt([...$attempt, 'status' => 'skipped', 'reason' => 'local_connection']);
                }

                continue;
            }
            $timeout = match ($group) {
                'local' => $left > 1 ? min(20, $budget[$group] / $left) : $budget[$group],
                default => min($left > 1 ? 7 : 11, $budget[$group] / $left),
            };
            if ($timeout < 1) {
                if ($recordAttempt !== null) {
                    $recordAttempt([...$attempt, 'status' => 'skipped']);
                }

                continue;
            }
            $agentInstructions = $agentId === null ? null : ($settings['agents'][$agentId]['instructions'] ?? null);
            $prompt = $instructions;
            if (filled($agentInstructions)) {
                $prompt .= "\nAgent guidance: ".mb_substr($agentInstructions, 0, 450);
            }
            $started = hrtime(true);
            $externalInput = $provider === 'ollama' ? $input : ($cloudInput ?? $input);
            $connectionFailed = false;
            try {
                [$text, $response] = match ($provider) {
                    'ollama' => $this->ollama($model, $prompt, $input, (int) $settings['ollama_port'], $accept !== null, $timeout),
                    'openai' => $this->openAi($model, $prompt, $externalInput, $key, $timeout),
                    'anthropic' => $this->anthropic($model, $prompt, $externalInput, $key, $timeout),
                    default => $this->chatCompletions($provider, $model, $prompt, $externalInput, $key, $accept !== null, $timeout),
                };
            } catch (ConnectionException) {
                $text = $response = null;
                $connectionFailed = true;
                $localConnectionFailed = $localConnectionFailed || $provider === 'ollama';
            }
            $elapsed = (hrtime(true) - $started) / 1_000_000_000;
            $budget[$group] -= $elapsed;
            $accepted = $text !== null && ($accept === null || $accept($text));
            $attempt += ['status' => $accepted ? 'accepted' : ($text === null ? 'unavailable' : 'rejected'),
                'duration_ms' => (int) ($elapsed * 1000)];
            if ($response !== null || $connectionFailed) {
                ($this->recordCall)([...$attempt, 'operation' => 'chat', 'origin' => $callContext['origin'] ?? 'reply',
                    'conversation_id' => $callContext['conversation_id'] ?? null], $response,
                    ['instructions' => $prompt, 'input' => $externalInput, 'output' => $text], $settings['cloud_keys']);
            }
            if ($recordAttempt !== null) {
                $recordAttempt($attempt);
            }

            if ($accepted) {
                return ['text' => $text, 'provider' => $provider, 'model' => $model, 'fallback' => $index > 0,
                    ...($workflow === null ? [] : ['workflow' => $workflow['name'], 'workflow_agent' => $agentId])];
            }
        }

        return null;
    }

    private function workflowCandidates(array $steps, array $settings, array $context, bool $localOnly, bool $cloudOnly, ?string $cloudProvider): array
    {
        $candidates = [];
        foreach ($steps as $step) {
            $provider = $step['provider'];
            $key = $settings['cloud_keys'][$provider] ?? null;
            $available = $provider === 'ollama' ? (! $cloudOnly && $settings['ollama_enabled'])
                : (! $localOnly && $settings['allow_cloud'] && filled($key) && ($cloudProvider === null || $cloudProvider === $provider));
            $agentId = $step['agent'] ?? $context['agent'] ?? null;
            $agent = $agentId === null ? null : ($settings['agents'][$agentId] ?? null);
            $collections = $context['collection_ids'] ?? [];
            $agentAllowed = $agentId === null || ($agent !== null && ($agent['enabled'] ?? true) === true
                && array_diff($collections, $agent['collections'] ?? $collections) === []);
            $reason = ! $available ? 'provider' : ($agentAllowed ? null : 'agent');
            $candidates[] = [$provider, $step['model'], $key, $agentId, $reason];
        }

        return $candidates;
    }

    private function cloudCandidates(array $settings, bool $complex, ?string $cloudProvider): array
    {
        $keys = $settings['cloud_keys'];
        $models = $settings['cloud']['models'];
        if ($models === []) {
            $models = [['provider' => 'openai', 'model' => $settings['openai_model'], 'tier' => 'economy']];
        }
        $candidates = [];
        foreach (['free' => 1, 'economy' => 2, 'advanced' => $complex ? 1 : 0] as $tier => $limit) {
            $tierCandidates = [];
            foreach ($models as $candidate) {
                $provider = $candidate['provider'];
                $model = $candidate['model'];
                if (($cloudProvider === null || $cloudProvider === $provider) && $candidate['tier'] === $tier
                    && filled($model) && filled($keys[$provider] ?? null)) {
                    $tierCandidates["{$provider}/{$model}"] = [$provider, $model, $keys[$provider]];
                }
            }
            $candidates = [...$candidates, ...array_slice(array_values($tierCandidates), 0, $limit)];
        }

        return $candidates;
    }

    private function ollama(string $model, string $instructions, string $input, int $port, bool $structured, float $timeout): array
    {
        // Accept only a bare, installed model name. Cloud-backed and registry references are excluded.
        if (! preg_match('/^[a-z0-9][a-z0-9._-]*(?::[a-z0-9._-]+)?$/iD', $model) || str_contains(strtolower($model), 'cloud')) {
            return [null, null];
        }

        if ($port < 1 || $port > 65535) {
            return [null, null];
        }

        $response = Http::asJson()->acceptJson()->withoutRedirecting()
            // CPU-only inference of small local models can exceed 12s under load.
            ->connectTimeout(3)->timeout($timeout)
            ->post("http://127.0.0.1:{$port}/api/chat", [
                'model' => $model,
                'stream' => false,
                'keep_alive' => '20m',
                ...($structured ? ['format' => self::REPLY_SELECTION_SCHEMA] : []),
                'options' => ['num_ctx' => 4096, 'num_predict' => $structured ? 72 : 128, ...($structured ? ['temperature' => 0] : [])],
                'messages' => [
                    ['role' => 'system', 'content' => $instructions],
                    ['role' => 'user', 'content' => $input],
                ],
            ]);

        return [$response->successful() && $response->json('done') === true
            && $response->json('done_reason') === 'stop'
            ? $this->validatedText($response->json('message.content')) : null, $response];
    }

    private function openAi(string $model, string $instructions, string $input, string $key, float $timeout): array
    {
        $response = Http::asJson()->acceptJson()->withoutRedirecting()->withToken($key)
            ->connectTimeout(3)->timeout($timeout)
            ->post('https://api.openai.com/v1/responses', [
                'model' => $model,
                'instructions' => $instructions,
                'input' => $input,
                'store' => false,
                'max_output_tokens' => 350,
            ]);

        if (! $response->successful() || $response->json('status') !== 'completed') {
            return [null, $response];
        }

        $parts = [];
        foreach ((array) $response->json('output', []) as $item) {
            if (data_get($item, 'type') !== 'message' || data_get($item, 'role') !== 'assistant') {
                continue;
            }

            foreach ((array) data_get($item, 'content', []) as $content) {
                if (data_get($content, 'type') === 'refusal') {
                    return [null, $response];
                }

                if (data_get($content, 'type') === 'output_text' && is_string(data_get($content, 'text'))) {
                    $parts[] = $content['text'];
                }
            }
        }

        return [$this->validatedText(implode("\n", $parts)), $response];
    }

    private function chatCompletions(string $provider, string $model, string $instructions, string $input, string $key, bool $structured, float $timeout): array
    {
        $openRouter = $provider === 'openrouter';
        $deepSeekFlash = $openRouter && preg_match('~^deepseek/deepseek-v4(?:\.1)?-flash(?:[-:]|$)~i', $model);
        $response = Http::asJson()->acceptJson()->withoutRedirecting()->withToken($key)
            ->connectTimeout(3)->timeout($timeout)
            ->post($openRouter ? 'https://openrouter.ai/api/v1/chat/completions' : 'https://api.deepseek.com/chat/completions', [
                'model' => $model, 'max_tokens' => 350, 'stream' => false,
                ...($structured ? ['response_format' => ['type' => 'json_object']] : []),
                ...($deepSeekFlash ? ['reasoning' => ['enabled' => false]] : []),
                ...($openRouter ? ['usage' => ['include' => true], 'provider' => ['sort' => 'price', 'data_collection' => 'deny', 'zdr' => true,
                    'require_parameters' => true]] : ['thinking' => ['type' => 'disabled']]),
                'messages' => [
                    ['role' => 'system', 'content' => $instructions],
                    ['role' => 'user', 'content' => $input],
                ],
            ]);

        return [$response->successful() && $response->json('choices.0.finish_reason') === 'stop'
            ? $this->validatedText($response->json('choices.0.message.content')) : null, $response];
    }

    private function anthropic(string $model, string $instructions, string $input, string $key, float $timeout): array
    {
        $response = Http::asJson()->acceptJson()->withoutRedirecting()->withHeaders([
            'x-api-key' => $key, 'anthropic-version' => '2023-06-01',
        ])->connectTimeout(3)->timeout($timeout)->post('https://api.anthropic.com/v1/messages', [
            'model' => $model, 'max_tokens' => 350, 'system' => $instructions,
            'messages' => [['role' => 'user', 'content' => $input]],
        ]);
        if (! $response->successful() || $response->json('stop_reason') !== 'end_turn') {
            return [null, $response];
        }

        return [$this->validatedText(collect($response->json('content', []))->where('type', 'text')->pluck('text')->implode("\n")), $response];
    }

    private function validatedText(mixed $text): ?string
    {
        return is_string($text) && trim($text) !== '' && mb_strlen($text) <= 2000 ? trim($text) : null;
    }
}
