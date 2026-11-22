<?php

namespace App\Actions;

use Illuminate\Support\Facades\DB;

class WorkspaceSettings
{
    public const array CONVERSE_REPLY_LANGUAGES = ['auto', 'bn', 'en', 'mixed'];

    /**
     * Module and application settings stored per section. Stored values are
     * JSON and override the code defaults below; nothing is cached, so a save
     * takes effect on the next request.
     */
    private const array DEFAULTS = [
        'application' => [
            'default_locale' => null, // Falls back to config('app.locale').
        ],
        'converse' => [
            // Master switch; defaults to the CONVERSE_AI_AUTO_REPLY environment value.
            'auto_reply' => null,
            // Handling mode assigned to conversations when they are created.
            'new_conversation_mode' => 'ai',
            'reply_language' => 'auto',
            // Channel types eligible for automatic replies unless a channel
            // account overrides this in the AI playground.
            'channel_types' => ['messenger' => true, 'whatsapp' => true],
        ],
    ];

    /** @return array<string, mixed> */
    public function application(): array
    {
        return $this->merged('application');
    }

    /** @return array<string, mixed> */
    public function converse(): array
    {
        return $this->merged('converse');
    }

    /** @param array<string, mixed> $values */
    public function put(string $section, array $values): void
    {
        $now = now();
        $rows = [];
        foreach ($values as $key => $value) {
            $rows[] = ['section' => $section, 'key' => $key, 'value' => json_encode($value, JSON_THROW_ON_ERROR),
                'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('settings')->upsert($rows, ['section', 'key'], ['value', 'updated_at']);
    }

    /** @return array<string, mixed> */
    private function merged(string $section): array
    {
        $defaults = self::DEFAULTS[$section];
        $stored = [];
        foreach (DB::table('settings')->where('section', $section)->get(['key', 'value']) as $row) {
            $decoded = json_decode((string) $row->value, true);
            $stored[$row->key] = $decoded;
        }

        $merged = [...$defaults, ...array_intersect_key($stored, $defaults)];
        if ($section === 'application' && $merged['default_locale'] === null) {
            $merged['default_locale'] = config('app.locale');
        }
        if ($section === 'converse' && $merged['auto_reply'] === null) {
            $merged['auto_reply'] = config('converse.ai.auto_reply') === true;
        }

        return $merged;
    }
}
