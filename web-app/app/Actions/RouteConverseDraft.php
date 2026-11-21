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

    public function __construct(private ConverseAiSettings $configuration) {}

    /** @return array{text: string, provider: string, model: string, fallback: bool}|null */
    public function __invoke(string $instructions, string $input, bool $localOnly = true, ?string $preferred = null, ?callable $accept = null, ?string $localFallback = null, ?string $localModel = null, ?int $localBudget = null, ?string $cloudInput = null, bool $complex = false, bool $cloudOnly = false, ?string $cloudProvider = null): ?array
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
        $budget = $localBudget ?? 36;
        $localDeadline = null;
        $cloudDeadline = null;
        $cloudRemaining = count($cloud);
        foreach ($candidates as $index => [$provider, $model, $key]) {
            if ($provider === 'ollama') {
                $localDeadline ??= hrtime(true) + $budget * 1_000_000_000;
                $reserve = count($local) > 1 && $model === $local[0][1] ? max(0, $budget - 20) : 0;
                $timeout = ($localDeadline - hrtime(true)) / 1_000_000_000 - $reserve;
            } else {
                $cloudDeadline ??= hrtime(true) + 21 * 1_000_000_000;
                $timeout = min($cloudRemaining > 1 ? 7 : 11, ($cloudDeadline - hrtime(true)) / 1_000_000_000);
                $cloudRemaining--;
            }
            if ($timeout < 1) {
                continue;
            }
            try {
                $externalInput = $cloudInput ?? $input;
                $text = match ($provider) {
                    'ollama' => $this->ollama($model, $instructions, $input, (int) $settings['ollama_port'], $accept !== null, $timeout),
                    'openai' => $this->openAi($model, $instructions, $externalInput, $key, $timeout),
                    'anthropic' => $this->anthropic($model, $instructions, $externalInput, $key, $timeout),
                    default => $this->chatCompletions($provider, $model, $instructions, $externalInput, $key, $accept !== null, $timeout),
                };
            } catch (ConnectionException) {
                continue;
            }

            if ($text !== null && ($accept === null || $accept($text))) {
                return ['text' => $text, 'provider' => $provider, 'model' => $model, 'fallback' => $index > 0];
            }
        }

        return null;
    }

    private function cloudCandidates(array $settings, bool $complex, ?string $cloudProvider): array
    {
        $keys = $settings['cloud_keys'];
        $models = $settings['cloud']['models'];
        if ($models === []) {
            $models = [['provider' => 'openai', 'model' => $settings['openai_model'], 'tier' => 'economy']];
        }
        $candidates = [];
        foreach ($complex ? ['free' => 1, 'economy' => 1, 'advanced' => 1] : ['free' => 1, 'economy' => 2] as $tier => $limit) {
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

    private function ollama(string $model, string $instructions, string $input, int $port, bool $structured, float $timeout): ?string
    {
        // Accept only a bare, installed model name. Cloud-backed and registry references are excluded.
        if (! preg_match('/^[a-z0-9][a-z0-9._-]*(?::[a-z0-9._-]+)?$/iD', $model) || str_contains(strtolower($model), 'cloud')) {
            return null;
        }

        if ($port < 1 || $port > 65535) {
            return null;
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

        return $response->successful() && $response->json('done') === true
            && $response->json('done_reason') === 'stop'
            ? $this->validatedText($response->json('message.content')) : null;
    }

    private function openAi(string $model, string $instructions, string $input, string $key, float $timeout): ?string
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
            return null;
        }

        $parts = [];
        foreach ((array) $response->json('output', []) as $item) {
            if (data_get($item, 'type') !== 'message' || data_get($item, 'role') !== 'assistant') {
                continue;
            }

            foreach ((array) data_get($item, 'content', []) as $content) {
                if (data_get($content, 'type') === 'refusal') {
                    return null;
                }

                if (data_get($content, 'type') === 'output_text' && is_string(data_get($content, 'text'))) {
                    $parts[] = $content['text'];
                }
            }
        }

        return $this->validatedText(implode("\n", $parts));
    }

    private function chatCompletions(string $provider, string $model, string $instructions, string $input, string $key, bool $structured, float $timeout): ?string
    {
        $openRouter = $provider === 'openrouter';
        $response = Http::asJson()->acceptJson()->withoutRedirecting()->withToken($key)
            ->connectTimeout(3)->timeout($timeout)
            ->post($openRouter ? 'https://openrouter.ai/api/v1/chat/completions' : 'https://api.deepseek.com/chat/completions', [
                'model' => $model, 'max_tokens' => 350, 'stream' => false,
                ...($structured ? ['response_format' => ['type' => 'json_object']] : []),
                ...($openRouter ? ['provider' => ['sort' => 'price', 'data_collection' => 'deny', 'zdr' => true,
                    'require_parameters' => true]] : ['thinking' => ['type' => 'disabled']]),
                'messages' => [
                    ['role' => 'system', 'content' => $instructions],
                    ['role' => 'user', 'content' => $input],
                ],
            ]);

        return $response->successful() && $response->json('choices.0.finish_reason') === 'stop'
            ? $this->validatedText($response->json('choices.0.message.content')) : null;
    }

    private function anthropic(string $model, string $instructions, string $input, string $key, float $timeout): ?string
    {
        $response = Http::asJson()->acceptJson()->withoutRedirecting()->withHeaders([
            'x-api-key' => $key, 'anthropic-version' => '2023-06-01',
        ])->connectTimeout(3)->timeout($timeout)->post('https://api.anthropic.com/v1/messages', [
            'model' => $model, 'max_tokens' => 350, 'system' => $instructions,
            'messages' => [['role' => 'user', 'content' => $input]],
        ]);
        if (! $response->successful() || $response->json('stop_reason') !== 'end_turn') {
            return null;
        }

        return $this->validatedText(collect($response->json('content', []))->where('type', 'text')->pluck('text')->implode("\n"));
    }

    private function validatedText(mixed $text): ?string
    {
        return is_string($text) && trim($text) !== '' && mb_strlen($text) <= 2000 ? trim($text) : null;
    }
}
