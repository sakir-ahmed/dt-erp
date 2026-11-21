<?php

namespace App\Actions;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class EmbedConverseText
{
    public function __construct(private ConverseAiSettings $configuration) {}

    /** @param list<string> $texts
     * @return list<list<float|int>>|null
     */
    public function __invoke(array $texts, string $model, float $timeout = 10): ?array
    {
        $settings = $this->configuration->get();
        $port = (int) $settings['ollama_port'];
        if (! $settings['ollama_enabled'] || $port < 1 || $port > 65535 || ! preg_match('/^[a-z0-9][a-z0-9._-]*(?::[a-z0-9._-]+)?$/iD', $model)
            || str_contains(strtolower($model), 'cloud')) {
            return null;
        }

        try {
            $response = Http::asJson()->withoutRedirecting()->connectTimeout(2)->timeout($timeout)
                ->post("http://127.0.0.1:{$port}/api/embed", ['model' => $model, 'input' => $texts]);
        } catch (ConnectionException) {
            return null;
        }

        $vectors = $response->successful() ? $response->json('embeddings') : null;
        if (! is_array($vectors) || ! array_is_list($vectors) || count($vectors) !== count($texts)) {
            return null;
        }

        foreach ($vectors as $vector) {
            if (! is_array($vector) || ! array_is_list($vector) || $vector === [] || count($vector) > 4096
                || count(array_filter($vector, fn ($value) => (is_float($value) || is_int($value)) && is_finite($value))) !== count($vector)) {
                return null;
            }
        }

        return $vectors;
    }

    public function similarity(array $left, array $right): float
    {
        if (count($left) !== count($right)) {
            return -1;
        }

        $dot = $leftNorm = $rightNorm = 0.0;
        foreach ($left as $index => $value) {
            $dot += $value * $right[$index];
            $leftNorm += $value ** 2;
            $rightNorm += $right[$index] ** 2;
        }

        return $leftNorm > 0 && $rightNorm > 0 ? $dot / sqrt($leftNorm * $rightNorm) : -1;
    }
}
