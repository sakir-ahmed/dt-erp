<?php

namespace App\Actions;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class RouteConverseDraft
{
    public function __construct(private ConverseAiSettings $configuration) {}

    /** @return array{text: string, provider: string, model: string, fallback: bool}|null */
    public function __invoke(string $instructions, string $input, bool $localOnly = true, ?string $preferred = null, ?callable $accept = null, ?string $localFallback = null, ?string $localModel = null, ?int $localBudget = null): ?array
    {
        $settings = $this->configuration->get();
        $cloudAllowed = ! $localOnly && $settings['allow_cloud'] === true;
        $primary = $cloudAllowed && ($preferred ?? $settings['primary']) === 'openai' ? 'openai' : 'ollama';
        $providers = $cloudAllowed ? [$primary, $primary === 'openai' ? 'ollama' : 'openai'] : ['ollama'];

        $ollamaModel = $localModel ?? $settings['ollama_model'];
        $brains = array_map(fn (string $provider): array => [$provider, $provider === 'ollama' ? $ollamaModel : $settings['openai_model']], $providers);
        $localFallback ??= $settings['ollama_fallback_model'];
        if (is_string($localFallback) && $localFallback !== $ollamaModel) {
            $brains[] = ['ollama', $localFallback];
        }

        $deadline = $localBudget === null ? null : hrtime(true) + $localBudget * 1_000_000_000;
        foreach ($brains as $index => [$provider, $model]) {
            if (! is_string($model) || trim($model) === '') {
                continue;
            }

            $timeout = $deadline === null ? 45 : min(45, ($deadline - hrtime(true)) / 1_000_000_000);
            if ($timeout <= 0) {
                return null;
            }
            try {
                $text = $provider === 'openai'
                    ? $this->openAi($model, $instructions, $input, $settings['openai_key'])
                    : $this->ollama($model, $instructions, $input, (int) $settings['ollama_port'], $accept !== null, $timeout);
            } catch (ConnectionException) {
                continue;
            }

            if ($text !== null && ($accept === null || $accept($text))) {
                return ['text' => $text, 'provider' => $provider, 'model' => $model, 'fallback' => $index > 0];
            }
        }

        return null;
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
                ...($structured ? ['format' => 'json'] : []),
                'options' => ['num_predict' => 250, ...($structured ? ['temperature' => 0] : [])],
                'messages' => [
                    ['role' => 'system', 'content' => $instructions],
                    ['role' => 'user', 'content' => $input],
                ],
            ]);

        return $response->successful() && $response->json('done') === true
            && $response->json('done_reason') === 'stop'
            ? $this->validatedText($response->json('message.content')) : null;
    }

    private function openAi(string $model, string $instructions, string $input, ?string $key): ?string
    {
        if (! is_string($key) || trim($key) === '') {
            return null;
        }

        $response = Http::asJson()->acceptJson()->withoutRedirecting()->withToken($key)
            ->connectTimeout(3)->timeout(12)
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

    private function validatedText(mixed $text): ?string
    {
        return is_string($text) && trim($text) !== '' && mb_strlen($text) <= 2000 ? trim($text) : null;
    }
}
