<?php

namespace App\Http\Controllers;

use App\Actions\RecordConverseAudit;
use App\Actions\WorkspaceSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    private const array LOCALES = ['en', 'bn'];

    private const array MODES = ['ai', 'human'];

    private const array CHANNEL_TYPES = ['messenger', 'whatsapp'];

    public function index(Request $request, WorkspaceSettings $settings): Response
    {
        return Inertia::render('Settings/Index', [
            'application' => $settings->application(),
            'converse' => $settings->converse(),
            'locales' => self::LOCALES,
            'modes' => self::MODES,
            'channelTypes' => self::CHANNEL_TYPES,
        ]);
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
