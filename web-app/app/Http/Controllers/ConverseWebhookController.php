<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessConverseWebhook;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ConverseWebhookController extends Controller
{
    public function verify(Request $request, string $provider): Response
    {
        $token = config("converse.webhooks.{$provider}.verify_token");
        abort_unless(is_string($token) && $token !== '', 503);

        $provided = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');
        abort_unless(
            $request->query('hub_mode') === 'subscribe'
            && is_string($provided) && hash_equals($token, $provided)
            && is_string($challenge) && ctype_digit($challenge),
            403,
        );

        return response($challenge, headers: ['Content-Type' => 'text/plain']);
    }

    public function receive(Request $request, string $provider): Response
    {
        $secret = config("converse.webhooks.{$provider}.secret");
        abort_unless(is_string($secret) && $secret !== '', 503);

        $raw = $request->getContent();
        abort_if(strlen($raw) > 1_000_000, 413);
        $signature = $request->header('X-Hub-Signature-256', '');
        abort_unless(hash_equals('sha256='.hash_hmac('sha256', $raw, $secret), $signature), 403);

        $payload = json_decode($raw, true);
        abort_unless(
            is_array($payload) && ($payload['object'] ?? null) === config("converse.webhooks.{$provider}.object")
            && is_array($payload['entry'] ?? null),
            400,
        );

        $key = ['provider' => $provider, 'digest' => hash('sha256', $raw)];
        DB::table('cv_webhook_events')->insertOrIgnore([
            ...$key,
            'payload' => Crypt::encryptString($raw),
            'received_at' => now(),
        ]);

        $event = DB::table('cv_webhook_events')->where($key)->firstOrFail(['id', 'processed_at']);
        if ($event->processed_at === null) {
            ProcessConverseWebhook::dispatch((int) $event->id)->onQueue('converse-inbound');
        }

        return response('OK', headers: ['Content-Type' => 'text/plain']);
    }
}
