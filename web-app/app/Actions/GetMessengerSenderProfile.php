<?php

namespace App\Actions;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class GetMessengerSenderProfile
{
    /** @return array{name?: string, photo?: string} */
    public function __invoke(int $conversationId): array
    {
        $sender = DB::table('cv_conversations as conversations')
            ->join('cv_contact_identities as identities', 'identities.id', '=', 'conversations.contact_identity_id')
            ->join('cv_channel_accounts as accounts', 'accounts.id', '=', 'identities.channel_account_id')
            ->where('conversations.id', $conversationId)
            ->where('accounts.type', 'messenger')
            ->where('accounts.enabled', true)
            ->where('accounts.is_demo', false)
            ->whereNotNull('accounts.send_token')
            ->first(['accounts.id as account_id', 'accounts.send_token', 'identities.external_id as sender_id']);

        if ($sender === null || ! $sender->send_token || ! ctype_digit($sender->sender_id)) {
            return [];
        }

        $key = 'converse:messenger-profile:'.hash('sha256', serialize([$sender->account_id, $sender->sender_id, $sender->send_token]));
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $profile = $this->fetch($sender->sender_id, $sender->send_token);
        Cache::put($key, $profile, $profile === [] ? now()->addMinutes(5) : now()->addHour());

        return $profile;
    }

    /** @return array{name?: string, photo?: string} */
    private function fetch(string $senderId, string $encryptedToken): array
    {
        $version = rawurlencode(config('converse.graph_version'));

        try {
            $response = Http::acceptJson()->withoutRedirecting()
                ->withToken(Crypt::decryptString($encryptedToken))
                ->connectTimeout(2)->timeout(3)
                ->get("https://graph.facebook.com/{$version}/{$senderId}", [
                    'fields' => 'first_name,last_name,profile_pic',
                ]);
        } catch (ConnectionException|DecryptException) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $nameParts = array_filter([$response->json('first_name'), $response->json('last_name')], is_string(...));
        $name = mb_substr(trim(implode(' ', $nameParts)), 0, 255);
        $photo = $response->json('profile_pic');
        $profile = [];

        if ($name !== '') {
            $profile['name'] = $name;
        }

        if (is_string($photo) && strlen($photo) <= 4096 && filter_var($photo, FILTER_VALIDATE_URL)) {
            $url = parse_url($photo);
            $host = strtolower($url['host'] ?? '');
            if (($url['scheme'] ?? null) === 'https' && ! isset($url['user']) && ! isset($url['port'])
                && (str_ends_with($host, '.fbcdn.net') || str_ends_with($host, '.fbsbx.com'))) {
                $profile['photo'] = $photo;
            }
        }

        return $profile;
    }
}
