<?php

namespace App\Actions;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ConverseAiSettings
{
    public function get(?object $saved = null): array
    {
        $saved ??= DB::table('cv_ai_settings')->find(1);
        $settings = config('converse.ai');
        foreach (['primary', 'allow_cloud', 'ollama_model', 'ollama_fallback_model', 'ollama_port', 'openai_model', 'embedding_model'] as $key) {
            if (($saved->{$key} ?? null) !== null) {
                $settings[$key] = $key === 'allow_cloud' ? (bool) $saved->{$key} : $saved->{$key};
            }
        }
        if ($saved?->routing) {
            $routing = json_decode($saved->routing, true, flags: JSON_THROW_ON_ERROR);
            $settings = array_replace($settings, array_intersect_key($routing, array_flip(['agents', 'rules'])));
        }
        if ($saved?->channels) {
            $settings['channels'] = array_replace($settings['channels'], json_decode($saved->channels, true, flags: JSON_THROW_ON_ERROR));
        }
        if ($saved?->openai_key_removed) {
            $settings['openai_key'] = null;
        }
        if ($saved?->openai_key) {
            try {
                $settings['openai_key'] = Crypt::decryptString($saved->openai_key);
            } catch (DecryptException) {
                $settings['openai_key'] = null;
            }
        }

        return $settings;
    }

    public function channel(int $id): array
    {
        return $this->get()['channels'][$id] ?? [];
    }

    public function public(): array
    {
        $settings = $this->get();

        return [
            ...array_intersect_key($settings, array_flip(['primary', 'allow_cloud', 'ollama_model',
                'ollama_fallback_model', 'ollama_port', 'openai_model', 'channels', 'auto_reply', 'agents', 'rules', 'embedding_model'])),
            'has_openai_key' => is_string($settings['openai_key']) && $settings['openai_key'] !== '',
        ];
    }
}
