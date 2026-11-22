<?php

namespace App\Http\Controllers;

use App\Actions\AiProviderCredentials;
use App\Actions\ConverseAiSettings;
use App\Actions\RecordConverseAudit;
use App\Actions\WorkspaceSettings;
use App\Enums\WorkspacePermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    private const array LOCALES = ['en', 'bn'];

    private const array MODES = ['ai', 'human'];

    private const array CHANNEL_TYPES = ['messenger', 'whatsapp'];

    public function index(Request $request, WorkspaceSettings $settings, AiProviderCredentials $credentials, ConverseAiSettings $configuration): Response
    {
        $aiProviders = [];
        if (! $request->user()->is_demo) {
            $configured = $credentials->get();
            $enabled = $credentials->enabled();
            foreach (AiProviderCredentials::PROVIDERS as $id => $name) {
                $aiProviders[] = ['id' => $id, 'name' => $name, 'configured' => isset($configured[$id]), 'enabled' => $enabled[$id]];
            }
        }
        $canConfigure = ! $request->user()->is_demo && $request->user()->can(WorkspacePermission::ManageConverseChannels->value);

        return Inertia::render('Settings/Index', [
            'application' => $settings->application(),
            'converse' => $settings->converse(),
            'locales' => self::LOCALES,
            'modes' => self::MODES,
            'channelTypes' => self::CHANNEL_TYPES,
            'replyLanguages' => WorkspaceSettings::CONVERSE_REPLY_LANGUAGES,
            'aiProviders' => $aiProviders,
            'converseAi' => $canConfigure ? [
                'providerSettings' => $configuration->public(),
                'channels' => DB::table('cv_channel_accounts')->where('is_demo', false)->orderBy('name')
                    ->select('id', 'name', 'type', 'enabled')->selectRaw('CASE WHEN send_token IS NULL THEN 0 ELSE 1 END as has_send_token')->get(),
                'collections' => DB::table('cv_knowledge_collections')->where('is_demo', false)
                    ->where('enabled', true)->orderBy('name')->get(['id', 'name']),
                'outboundEnabled' => config('converse.outbound_enabled') === true,
            ] : null,
        ]);
    }

    public function updateAiProviders(Request $request, AiProviderCredentials $credentials, RecordConverseAudit $audit): RedirectResponse
    {
        abort_if($request->user()->is_demo, 403);
        $rules = [];
        foreach (array_keys(AiProviderCredentials::PROVIDERS) as $provider) {
            $rules[$provider.'_key'] = ['nullable', 'string', 'max:4096', "prohibited_if:remove_{$provider}_key,true"];
            $rules["remove_{$provider}_key"] = ['sometimes', 'boolean'];
            $rules["enable_{$provider}"] = ['sometimes', 'boolean'];
        }
        $data = $request->validate($rules);
        $keys = $enabled = [];
        foreach (array_keys(AiProviderCredentials::PROVIDERS) as $provider) {
            $key = $data[$provider.'_key'] ?? null;
            $remove = (bool) ($data["remove_{$provider}_key"] ?? false);
            if ($remove || $key !== null) {
                $keys[$provider] = $remove ? null : $key;
            }
            if (array_key_exists("enable_{$provider}", $data)) {
                $enabled[$provider] = (bool) $data["enable_{$provider}"];
            }
        }

        if ($keys !== [] || $enabled !== []) {
            DB::transaction(function () use ($request, $credentials, $audit, $keys, $enabled): void {
                $credentials->put($keys);
                $credentials->setEnabled($enabled);
                $audit($request->user()->id, 'settings.ai_provider_keys_updated', 'settings', 0, [
                    'keys' => array_map(fn (?string $key): string => $key === null ? 'removed' : 'saved', $keys),
                    'enabled' => $enabled,
                ]);
            });
            Inertia::flash('success', __('workspace.settings_saved'));
        }

        return back();
    }

    public function readAiProviderKey(Request $request, AiProviderCredentials $credentials, RecordConverseAudit $audit, string $provider): JsonResponse
    {
        abort_if($request->user()->is_demo, 403);
        $data = $request->validate(['action' => ['required', 'in:reveal,copy']]);
        $key = $credentials->get()[$provider] ?? null;
        abort_if($key === null, 404);

        $audit($request->user()->id, 'settings.ai_provider_key_accessed', 'settings', 0, [
            'provider' => $provider,
            'operation' => $data['action'],
        ]);

        return response()->json(['key' => $key], headers: ['Cache-Control' => 'no-store, private']);
    }

    public function updateApplication(Request $request, WorkspaceSettings $settings, RecordConverseAudit $audit): RedirectResponse
    {
        $validated = $request->validate([
            'default_locale' => ['required', 'string', 'in:'.implode(',', self::LOCALES)],
        ]);
        $this->save($request, $settings, $audit, 'application', $validated);

        return back();
    }

    public function updateConverse(Request $request, WorkspaceSettings $settings, RecordConverseAudit $audit): RedirectResponse
    {
        $validated = $request->validate([
            'auto_reply' => ['required', 'boolean'],
            'new_conversation_mode' => ['required', 'string', 'in:'.implode(',', self::MODES)],
            'reply_language' => ['sometimes', 'required', 'string', Rule::in(WorkspaceSettings::CONVERSE_REPLY_LANGUAGES)],
            'channel_types' => ['required', 'array:'.implode(',', self::CHANNEL_TYPES)],
            'channel_types.*' => ['required', 'boolean'],
        ]);
        $this->save($request, $settings, $audit, 'converse', $validated);

        return back();
    }

    /** @param array<string, mixed> $values */
    private function save(Request $request, WorkspaceSettings $settings, RecordConverseAudit $audit, string $section, array $values): void
    {
        $previous = $settings->{$section}();
        $settings->put($section, $values);
        $audit($request->user()->id, 'settings.updated', 'settings', 0, ['section' => $section, 'from' => $previous, 'to' => $values]);
        Inertia::flash('success', __('workspace.settings_saved'));
    }
}
