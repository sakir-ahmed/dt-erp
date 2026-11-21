<?php

namespace App\Actions;

use Illuminate\Support\Facades\DB;

class ConverseAiSettings
{
    public function __construct(private AiProviderCredentials $credentials) {}

    public function get(?object $saved = null): array
    {
        $saved ??= DB::table('cv_ai_settings')->find(1);
        $settings = config('converse.ai');
        foreach (['primary', 'allow_cloud', 'ollama_enabled', 'ollama_model', 'ollama_fallback_model', 'ollama_port', 'openai_model', 'embedding_model'] as $key) {
            if (($saved->{$key} ?? null) !== null) {
                $settings[$key] = in_array($key, ['allow_cloud', 'ollama_enabled'], true) ? (bool) $saved->{$key} : $saved->{$key};
            }
        }
        if ($saved?->routing) {
            $routing = json_decode($saved->routing, true, flags: JSON_THROW_ON_ERROR);
            $settings = array_replace($settings, array_intersect_key($routing, array_flip(['agents', 'rules', 'cloud', 'workflows'])));
        }
        if ($saved?->channels) {
            $settings['channels'] = array_replace($settings['channels'], json_decode($saved->channels, true, flags: JSON_THROW_ON_ERROR));
        }

        return [...$settings, 'cloud_keys' => array_intersect_key(
            $this->credentials->get(), array_filter($this->credentials->enabled()),
        )];
    }

    public function channel(int $id): array
    {
        return $this->get()['channels'][$id] ?? [];
    }

    public function public(): array
    {
        $settings = $this->get();

        return [
            ...array_intersect_key($settings, array_flip(['primary', 'allow_cloud', 'ollama_enabled', 'ollama_model',
                'ollama_fallback_model', 'ollama_port', 'openai_model', 'channels', 'auto_reply', 'agents', 'rules', 'cloud', 'embedding_model', 'workflows'])),
            'has_openai_key' => isset($settings['cloud_keys']['openai']),
            'cloud_providers' => array_keys($settings['cloud_keys']),
        ];
    }
}
