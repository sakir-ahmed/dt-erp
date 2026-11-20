<?php

namespace App\Actions;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Query\Builder;
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
        $sender = $this->senders()->where('conversations.id', $conversationId)->first();
        if ($sender === null || ! $sender->send_token || ! ctype_digit($sender->sender_id)) {
            return [];
        }

        $key = $this->cacheKey($sender);
        $cached = Cache::get($key);
        $profile = is_array($cached) ? $cached : $this->fetch($sender->sender_id, $sender->send_token);
        if (! is_array($cached)) {
            Cache::put($key, $profile, $profile === [] ? now()->addMinutes(5) : now()->addHour());
        }
        if (isset($profile['name']) && $sender->stored_name === null) {
            DB::table('cv_contacts')->where('id', $sender->contact_id)->whereNull('name')->update([
                'name' => $profile['name'], 'updated_at' => now(),
            ]);
        }

        return $profile;
    }

    /** @return array<int, array|null> Cached profiles or null when an eligible profile needs a refresh. */
    public function cached(array $conversationIds): array
    {
        $senders = $this->senders()->whereIn('conversations.id', $conversationIds)->get()
            ->filter(fn (object $sender) => $sender->send_token && ctype_digit($sender->sender_id))
            ->mapWithKeys(fn (object $sender) => [$sender->conversation_id => $this->cacheKey($sender)]);
        $profiles = Cache::many($senders->values()->all());

        return $senders->map(fn (string $key) => is_array($profiles[$key] ?? null) ? $profiles[$key] : null)->all();
    }

    private function cacheKey(object $sender): string
    {
        return 'converse:messenger-profile:'.hash('sha256', serialize([$sender->account_id, $sender->sender_id, $sender->send_token]));
    }

    private function senders(): Builder
    {
        return DB::table('cv_conversations as conversations')
            ->join('cv_contact_identities as identities', 'identities.id', '=', 'conversations.contact_identity_id')
            ->join('cv_channel_accounts as accounts', 'accounts.id', '=', 'identities.channel_account_id')
            ->where('accounts.type', 'messenger')
            ->where('accounts.enabled', true)
            ->where('accounts.is_demo', false)
            ->whereNotNull('accounts.send_token')
            ->join('cv_contacts as contacts', 'contacts.id', '=', 'identities.contact_id')
            ->select('conversations.id as conversation_id', 'accounts.id as account_id', 'accounts.send_token',
                'identities.external_id as sender_id', 'identities.contact_id', 'contacts.name as stored_name');

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
