<?php

namespace App\Actions;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class AiProviderCredentials
{
    public const array PROVIDERS = [
        'openrouter' => 'OpenRouter',
        'openai' => 'OpenAI',
        'deepseek' => 'DeepSeek',
        'anthropic' => 'Claude (Anthropic)',
    ];

    private const string SECTION = 'ai_provider_credentials';

    public function __construct(private WorkspaceSettings $settings) {}

    /** @return array<string, string> */
    public function get(): array
    {
        $keys = [];
        $stored = DB::table('settings')->where('section', self::SECTION)
            ->whereIn('key', array_keys(self::PROVIDERS))->pluck('value', 'key');
        foreach ($stored as $provider => $value) {
            $encrypted = json_decode($value ?? 'null', true);
            if (! is_string($encrypted) || $encrypted === '') {
                continue;
            }

            try {
                $key = Crypt::decryptString($encrypted);
                if ($key !== '') {
                    $keys[$provider] = $key;
                }
            } catch (DecryptException) {
                continue;
            }
        }

        return $keys;
    }

    /** @param array<string, string|null> $keys */
    public function put(array $keys): void
    {
        $this->settings->put(self::SECTION, array_map(
            fn (?string $key): ?string => $key === null ? null : Crypt::encryptString($key),
            array_intersect_key($keys, self::PROVIDERS),
        ));
    }
}
