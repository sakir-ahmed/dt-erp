<?php

namespace App\Http\Controllers;

use App\Actions\AiProviderCredentials;
use App\Actions\RecordConverseAudit;
use App\Actions\WorkspaceSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    private const array LOCALES = ['en', 'bn'];

    private const array MODES = ['ai', 'human'];

    private const array CHANNEL_TYPES = ['messenger', 'whatsapp'];

    public function index(Request $request, WorkspaceSettings $settings, AiProviderCredentials $credentials): Response
    {
        $aiProviders = [];
        if (! $request->user()->is_demo) {
            $configured = $credentials->get();
            foreach (AiProviderCredentials::PROVIDERS as $id => $name) {
                $aiProviders[] = ['id' => $id, 'name' => $name, 'configured' => isset($configured[$id])];
            }
        }

        return Inertia::render('Settings/Index', [
            'application' => $settings->application(),
            'converse' => $settings->converse(),
            'locales' => self::LOCALES,
            'modes' => self::MODES,
            'channelTypes' => self::CHANNEL_TYPES,
            'aiProviders' => $aiProviders,
        ]);
    }

    public function updateAiProviders(Request $request, AiProviderCredentials $credentials, RecordConverseAudit $audit): RedirectResponse
    {
        abort_if($request->user()->is_demo, 403);
        $rules = [];
        foreach (array_keys(AiProviderCredentials::PROVIDERS) as $provider) {
            $rules[$provider.'_key'] = ['nullable', 'string', 'max:4096', "prohibited_if:remove_{$provider}_key,true"];
            $rules["remove_{$provider}_key"] = ['sometimes', 'boolean'];
        }
        $data = $request->validate($rules);
        $keys = $changes = [];
        foreach (array_keys(AiProviderCredentials::PROVIDERS) as $provider) {
            $key = $data[$provider.'_key'] ?? null;
            $remove = (bool) ($data["remove_{$provider}_key"] ?? false);
            if ($remove || $key !== null) {
                $keys[$provider] = $remove ? null : $key;
                $changes[$provider] = $remove ? 'removed' : 'saved';
            }
        }

        if ($changes !== []) {
            DB::transaction(function () use ($request, $credentials, $audit, $keys, $changes): void {
                $credentials->put($keys);
                $audit($request->user()->id, 'settings.ai_provider_keys_updated', 'settings', 0, $changes);
            });
            Inertia::flash('success', __('workspace.settings_saved'));
        }

        return back();
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
