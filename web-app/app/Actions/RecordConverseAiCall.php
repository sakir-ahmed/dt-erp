<?php

namespace App\Actions;

use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecordConverseAiCall
{
    public function __invoke(array $call, ?Response $response, array $details, array $secrets = []): void
    {
        $provider = $call['provider'];
        $usage = $this->usage($provider, $response, $call['operation']);
        $model = $response?->json('model');
        if (! is_string($model) || ! preg_match('~^[a-zA-Z0-9][a-zA-Z0-9._:/-]{0,254}$~D', $model)) {
            $model = $call['model'];
        }
        $local = $provider === 'ollama';
        $free = $local || ($provider === 'openrouter' && (str_ends_with($model, ':free') || str_ends_with($call['model'], ':free')));
        if ($usage['reported_cost_usd'] !== null && $usage['reported_cost_usd'] > 0) {
            $free = false;
        }

        if (isset($details['input'])) {
            $input = json_decode($details['input'], true);
            if (is_array($input) && array_key_exists('memory', $input)) {
                unset($input['memory']);
                $details['input'] = json_encode($input, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        $summary = array_map(
            fn (?string $text): ?string => $text === null ? null : $this->redact($text, $secrets),
            $details,
        );
        if ($call['status'] === 'unavailable') {
            $summary['reason'] = $this->unavailableReason($response);
        }
        try {
            DB::table('cv_ai_calls')->insert([
                ...$call, ...$usage, 'model' => $model, 'billing' => $local ? 'local' : ($free ? 'free' : 'paid'),
                'http_status' => $response?->status(),
                'detail' => Crypt::encryptString(json_encode($summary, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)),
                'called_at' => now()->subMilliseconds($call['duration_ms']),
            ]);
        } catch (QueryException) {
            Log::warning('Unable to record Converse AI call', ['provider' => $provider, 'operation' => $call['operation']]);
        }
    }

    private function unavailableReason(?Response $response): ?string
    {
        return match (true) {
            $response === null => 'connection_failed',
            in_array($response->json('error.code'), ['insufficient_quota', 'billing_hard_limit_reached'], true),
            $response->status() === 402 => 'quota',
            $response->status() === 429 => 'rate_limited',
            $response->status() === 404 => 'model_unavailable',
            $response->json('choices.0.finish_reason') === 'length',
            $response->json('incomplete_details.reason') === 'max_output_tokens',
            $response->json('done_reason') === 'length',
            $response->json('stop_reason') === 'max_tokens' => 'output_limit',
            default => null,
        };
    }

    private function usage(string $provider, ?Response $response, string $operation): array
    {
        $input = $output = $total = $cost = null;
        if ($response !== null) {
            [$input, $output, $total] = match ($provider) {
                'ollama' => [$response->json('prompt_eval_count'), $response->json('eval_count'), null],
                'openai', 'anthropic' => [$response->json('usage.input_tokens'), $response->json('usage.output_tokens'), $response->json('usage.total_tokens')],
                default => [$response->json('usage.prompt_tokens'), $response->json('usage.completion_tokens'), $response->json('usage.total_tokens')],
            };
            if ($provider === 'anthropic' && is_int($input)) {
                $cached = [$response->json('usage.cache_creation_input_tokens'), $response->json('usage.cache_read_input_tokens')];
                $input += array_sum(array_filter($cached, is_int(...)));
            }
            if ($provider === 'openrouter') {
                $reported = $response->json('usage.cost');
                if (is_numeric($reported) && is_finite((float) $reported) && $reported >= 0 && $reported < 1_000_000) {
                    $cost = number_format((float) $reported, 10, '.', '');
                }
            }
        }

        $input = is_int($input) && $input >= 0 ? $input : null;
        $output = is_int($output) && $output >= 0 ? $output : ($operation === 'embedding' && $input !== null ? 0 : null);
        $total = is_int($total) && $total >= 0 ? $total : ($input !== null && $output !== null ? $input + $output : null);

        return ['input_tokens' => $input, 'output_tokens' => $output, 'total_tokens' => $total,
            'reported_cost_usd' => $cost];
    }

    private function redact(string $text, array $secrets): string
    {
        $text = str_replace(array_values(array_filter($secrets)), '[redacted]', $text);
        if (preg_match('/\b(password|passcode|otp|cvv|api[ -]?key|bank account|card number|private key)\b|পাসওয়ার্ড|ওটিপি|পিন কোড|ব্যাংক অ্যাকাউন্ট/ui', $text)) {
            return '[redacted: credential-related content]';
        }

        $text = preg_replace('~https?://[^\s"<>]+|[\w.+-]+@[\w.-]+\.[a-zA-Z]{2,}|(?<!\p{N})\+?\p{N}(?:[ .()-]*\p{N}){6,}|\b(?:sk[-_]|AIza|gh[pousr]_|Bearer\s+)[a-zA-Z0-9_.-]{12,}~ui', '[redacted]', $text);

        return mb_substr($text ?? '[redacted]', 0, 12000);
    }
}
