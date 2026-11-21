<?php

namespace App\Actions;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SaveConverseInboundAttachment
{
    private const array TYPES = [
        'application/pdf' => ['pdf', 12_000_000],
        'image/jpeg' => ['jpg', 5_000_000],
        'image/png' => ['png', 5_000_000],
        'image/webp' => ['webp', 5_000_000],
    ];

    /**
     * Download a verified provider attachment for an already-recorded inbound
     * message and link it as the message's conversation asset. Returns the
     * asset id, or null when anything looks wrong; the message survives.
     */
    public function __invoke(int $message, int $account, string $channel, string $locator, ?string $name = null, ?string $sha256 = null): ?int
    {
        $bytes = $channel === 'whatsapp'
            ? $this->downloadWhatsApp($account, $locator, $sha256)
            : $this->downloadMessenger($locator);
        if ($bytes === null) {
            return null;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! is_string($mime) || ! isset(self::TYPES[$mime]) || strlen($bytes) > self::TYPES[$mime][1]) {
            return null;
        }

        $digest = hash('sha256', $bytes);
        $name = Str::slug(pathinfo((string) $name, PATHINFO_FILENAME));
        $name = mb_substr($name ?: 'attachment', 0, 110).'.'.self::TYPES[$mime][0];
        $path = 'converse/inbound/'.Str::uuid().'.'.self::TYPES[$mime][0];

        try {
            return DB::transaction(function () use ($message, $bytes, $path, $name, $mime, $digest): int {
                $conversation = DB::table('cv_messages')->where('id', $message)->value('conversation_id');
                $id = DB::table('cv_outbound_assets')->insertGetId([
                    'conversation_id' => $conversation, 'owner_id' => null,
                    'name' => $name, 'title' => $name, 'path' => $path,
                    'mime' => $mime, 'bytes' => strlen($bytes), 'digest' => $digest,
                    'approved' => false, 'created_at' => now(),
                ]);
                Storage::disk('local')->put($path, $bytes);
                DB::table('cv_messages')->where('id', $message)->whereNull('outbound_asset_id')
                    ->update(['outbound_asset_id' => $id]);

                return (int) $id;
            }, attempts: 3);
        } catch (\Throwable) {
            Storage::disk('local')->delete($path);

            return null;
        }
    }

    private function downloadMessenger(string $locator): ?string
    {
        if (strlen($locator) > 2048 || ! filter_var($locator, FILTER_VALIDATE_URL)) {
            return null;
        }
        $url = parse_url($locator);
        $host = strtolower(is_array($url) ? ($url['host'] ?? '') : '');

        if (($url['scheme'] ?? null) !== 'https' || isset($url['user']) || isset($url['port'])
            || ! (str_ends_with($host, '.fbcdn.net') || str_ends_with($host, '.fbsbx.com'))) {
            return null;
        }

        try {
            $response = Http::connectTimeout(3)->timeout(20)->get($locator);
        } catch (ConnectionException) {
            return null;
        }

        return $response->successful() && $response->body() !== '' ? $response->body() : null;
    }

    private function downloadWhatsApp(int $account, string $media, ?string $sha256): ?string
    {
        $token = DB::table('cv_channel_accounts')->where('id', $account)->value('send_token');
        try {
            $token = Crypt::decryptString((string) $token);
        } catch (DecryptException) {
            return null;
        }

        $version = rawurlencode(config('converse.graph_version'));

        try {
            $request = Http::acceptJson()->withoutRedirecting()->withToken($token)->connectTimeout(3)->timeout(10);
            $metadata = $request->get("https://graph.facebook.com/{$version}/{$media}");
            $url = $metadata->successful() ? $metadata->json('url') : null;
            if (! is_string($url) || strlen($url) > 2048 || ! filter_var($url, FILTER_VALIDATE_URL)) {
                return null;
            }
            $parts = parse_url($url);
            $host = strtolower(is_array($parts) ? ($parts['host'] ?? '') : '');
            if (($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['port'])
                || ! str_ends_with($host, '.whatsapp.net')) {
                return null;
            }

            $file = $request->timeout(20)->get($url);
        } catch (ConnectionException) {
            return null;
        }

        $bytes = $file->successful() ? $file->body() : null;
        if ($bytes === null || $bytes === ''
            || (is_string($sha256) && strlen($sha256) === 64 && ! hash_equals($sha256, hash('sha256', $bytes)))) {
            return null;
        }

        return $bytes;
    }
}
