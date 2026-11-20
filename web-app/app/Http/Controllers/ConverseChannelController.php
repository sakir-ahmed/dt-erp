<?php

namespace App\Http\Controllers;

use App\Actions\RecordConverseAudit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ConverseChannelController extends Controller
{
    public function index(Request $request): Response
    {
        $channels = DB::table('cv_channel_accounts')->where('is_demo', (bool) $request->user()->is_demo)
            ->select('id', 'type', 'name', 'external_id', 'enabled')
            ->selectRaw('CASE WHEN send_token IS NULL THEN 0 ELSE 1 END as has_send_token')
            ->orderBy('name')->orderBy('id')->simplePaginate(25);
        foreach ($channels->items() as $channel) {
            $channel->enabled = (bool) $channel->enabled;
            $channel->has_send_token = (bool) $channel->has_send_token;
        }

        return Inertia::render('Converse/Channels', ['channels' => $channels, 'canConfigureToken' => ! $request->user()->is_demo]);
    }

    public function store(Request $request, RecordConverseAudit $audit): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:messenger,whatsapp'],
            'name' => ['required', 'string', 'max:255'],
            'external_id' => ['required', 'string', 'regex:/^\d{1,64}$/D'],
        ]);

        try {
            DB::transaction(function () use ($request, $data, $audit): void {
                $id = DB::table('cv_channel_accounts')->insertGetId([
                    ...$data,
                    'enabled' => true,
                    'is_demo' => (bool) $request->user()->is_demo,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $audit($request->user()->id, 'channel.created', 'channel', (int) $id, $data);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['external_id' => __('workspace.channel_id_taken')]);
        }

        Inertia::flash('success', __('workspace.channel_added'));

        return to_route('converse.channels.index');
    }

    public function update(Request $request, int $channel, RecordConverseAudit $audit): RedirectResponse
    {
        $scope = DB::table('cv_channel_accounts')->where('id', $channel)->where('is_demo', (bool) $request->user()->is_demo);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'enabled' => ['required', 'boolean'],
            'send_token' => ['nullable', 'string', 'max:4096', 'prohibited_if:remove_send_token,true'],
            'remove_send_token' => ['sometimes', 'boolean'],
        ]);
        $token = $data['send_token'] ?? null;
        $remove = (bool) ($data['remove_send_token'] ?? false);
        $data = ['name' => $data['name'], 'enabled' => (bool) $data['enabled']];

        if ($request->user()->is_demo && ($token !== null || $remove)) {
            throw ValidationException::withMessages(['send_token' => __('workspace.reply_channel_unavailable')]);
        }

        DB::transaction(function () use ($request, $channel, $scope, $data, $audit, $token, $remove): void {
            $before = $scope->lockForUpdate()->firstOrFail(['name', 'enabled']);
            $previous = ['name' => $before->name, 'enabled' => (bool) $before->enabled];
            if ($previous !== $data || $token !== null || $remove) {
                $changes = ['from' => $previous, 'to' => $data];
                $credentials = [];
                if ($token !== null || $remove) {
                    $credentials['send_token'] = $remove ? null : Crypt::encryptString($token);
                    $changes['send_token_configured'] = ! $remove;
                }
                $scope->update([...$data, ...$credentials, 'updated_at' => now()]);
                $audit($request->user()->id, 'channel.updated', 'channel', $channel, $changes);
            }
        });

        Inertia::flash('success', __('workspace.channel_updated'));

        return to_route('converse.channels.index');
    }
}
