<?php

namespace App\Http\Controllers;

use App\Actions\ComposeConverseReply;
use App\Actions\ConverseAiSettings;
use App\Actions\ConverseOutboundAssets;
use App\Actions\GetMessengerSenderProfile;
use App\Actions\RecordConverseAudit;
use App\Actions\RecordConverseNotification;
use App\Actions\RecordWhatsAppDeliveryStatus;
use App\Actions\SendConverseReply;
use App\Actions\WhatsAppRecipient;
use App\Enums\WorkspacePermission;
use App\Jobs\RefreshConverseMessengerProfile;
use App\Jobs\SendConverseBroadcast;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConverseInboxController extends Controller
{
    public function __construct(private ConverseAiSettings $aiSettings, private ConverseOutboundAssets $assets) {}

    private const array CHANNELS = ['messenger', 'whatsapp'];

    private const array MODES = ['ai', 'human', 'paused'];

    private const array STATUSES = ['open', 'pending', 'snoozed', 'resolved'];

    public function __invoke(Request $request, SendConverseReply $send, GetMessengerSenderProfile $profile, ?int $conversation = null): Response
    {
        $search = $request->query('search');
        $search = is_string($search) ? mb_substr(trim($search), 0, 100) : '';
        $channel = $request->query('channel');
        $channel = in_array($channel, self::CHANNELS, true) ? $channel : '';
        $status = $request->query('status');
        $status = in_array($status, self::STATUSES, true) ? $status : '';
        $tags = $this->tagFilter($request);
        $scope = $this->scope($request);

        $thread = $conversation === null ? null : (clone $scope)->where('conversations.id', $conversation)->addSelect('accounts.id as channel_account_id', 'conversations.memory', 'contacts.language_preference')->first();
        abort_if($conversation !== null && $thread === null, 404);

        $conversations = (clone $scope)
            ->when($status !== '', fn (Builder $query) => $query->where('conversations.status', $status))
            ->when($channel !== '', fn (Builder $query) => $query->where('accounts.type', $channel))
            ->when($tags !== [], fn (Builder $query) => $this->whereAllTags($query, $tags))
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                $query->whereLike('contacts.name', "%{$search}%")
                    ->orWhereLike('accounts.name', "%{$search}%")
                    ->orWhereExists(fn (Builder $messages) => $messages->selectRaw('1')->from('cv_messages')
                        ->whereColumn('cv_messages.conversation_id', 'conversations.id')
                        ->whereLike('cv_messages.body', "%{$search}%"));
            }))
            ->orderByDesc('conversations.last_message_at')->orderByDesc('conversations.id')
            ->simplePaginate(25, pageName: 'inbox_page')->withQueryString();

        $messages = $thread === null ? null : DB::table('cv_messages as message')
            ->leftJoin('cv_outbound_assets as asset', 'message.outbound_asset_id', '=', 'asset.id')
            ->where('message.conversation_id', $thread->id)
            ->orderByDesc('message.occurred_at')->orderByDesc('message.id')
            ->select('message.id', 'message.direction', 'message.sender_type', 'message.body', 'message.occurred_at',
                'message.delivery_status', 'message.attachment_status', 'asset.name as attachment_name',
                'asset.mime as attachment_mime', 'asset.bytes as attachment_bytes')
            ->simplePaginate(50, pageName: 'thread_page')->withQueryString();
        foreach ($messages?->items() ?? [] as $row) {
            $row->occurred_at = Carbon::parse($row->occurred_at)->toISOString();
        }

        $demo = (bool) $request->user()->is_demo;
        $selectedProfile = $thread && $thread->channel === 'messenger' && ! $demo ? $profile((int) $thread->id) : [];
        $cachedProfiles = $demo ? [] : $profile->cached(collect($conversations->items())->pluck('id')->all());
        $conversationTags = $this->conversationTags(array_merge([$thread?->id], collect($conversations->items())->pluck('id')->all()));
        $refresh = null;
        foreach ($conversations->items() as $row) {
            $row->last_message_at = Carbon::parse($row->last_message_at)->toISOString();
            $row->tags = $conversationTags[$row->id] ?? [];
            if (array_key_exists($row->id, $cachedProfiles) && $cachedProfiles[$row->id] === null) {
                $refresh ??= (int) $row->id;
            }
            $this->applyContactProfile($row, $row->id === $thread?->id ? $selectedProfile : ($cachedProfiles[$row->id] ?? []));
        }
        if ($refresh !== null && config('queue.default') !== 'sync') {
            RefreshConverseMessengerProfile::dispatch($refresh)->onQueue('converse-inbound');
        }
        if ($thread) {
            $this->applyContactProfile($thread, $selectedProfile);
            $thread->tags = $conversationTags[$thread->id] ?? [];
        }

        $canManage = $request->user()->can(WorkspacePermission::ManageConverseInbox->value);
        // The full number is actionable for reply managers only; viewers keep the masked suffix.
        if (! $canManage) {
            foreach ([...$conversations->items(), $thread] as $row) {
                if ($row !== null) {
                    unset($row->contact_phone);
                }
            }
        }
        $channelId = $thread?->channel_account_id;
        if ($thread) {
            $thread->memory = json_decode($thread->memory ?? '{}', true, flags: JSON_THROW_ON_ERROR);
            unset($thread->channel_account_id);
        }

        return Inertia::render('Converse/Inbox', [
            'aiTrace' => fn () => $thread && $canManage
                ? DB::table('cv_ai_turns as turn')->join('cv_messages as inbound', 'inbound.id', '=', 'turn.inbound_message_id')
                    ->where('inbound.conversation_id', $thread->id)->orderByDesc('turn.id')
                    ->first(['turn.language', 'turn.intent', 'turn.decision', 'turn.provider', 'turn.model', 'turn.fallback', 'turn.source_ids'])
                : null,
            'conversations' => $conversations,
            'thread' => $thread,
            'messages' => $messages,
            'filters' => ['search' => $search, 'channel' => $channel, 'status' => $status, 'tags' => $tags],
            'channels' => self::CHANNELS,
            'modes' => self::MODES,
            'statuses' => self::STATUSES,
            'tags' => fn () => DB::table('cv_conversation_tags as tag')
                ->join('cv_conversation_tag as pivot', 'pivot.tag_id', '=', 'tag.id')
                ->join('cv_conversations as conversations', 'conversations.id', '=', 'pivot.conversation_id')
                ->join('cv_contact_identities as identities', 'identities.id', '=', 'conversations.contact_identity_id')
                ->join('cv_channel_accounts as accounts', 'accounts.id', '=', 'identities.channel_account_id')
                ->where('accounts.is_demo', $demo)
                ->groupBy('tag.id', 'tag.name')
                ->orderBy('tag.name')
                ->get(['tag.id', 'tag.name', DB::raw('count(distinct pivot.conversation_id) as conversations_count')]),
            'canManageInbox' => $canManage,
            'catalogAttachments' => $canManage && $thread ? $this->assets->catalog($this->aiSettings->channel((int) $channelId)['collections'] ?? [])
                ->orderBy('asset.title')->limit(100)->get(['asset.id', 'asset.title']) : [],
            'canDraftReply' => $canManage && $thread && ! $request->user()->is_demo
                && ($this->aiSettings->channel((int) $channelId)['collections'] ?? []) !== [],
            'replyBlockedReason' => $canManage && $thread ? $send->unavailableReason($request->user(), (int) $thread->id) : null,
        ]);
    }

    public function draft(Request $request, int $conversation, ComposeConverseReply $compose): JsonResponse
    {
        $thread = $this->scope($request)->where('conversations.id', $conversation)
            ->addSelect('accounts.id as channel_account_id', 'conversations.memory', 'contacts.language_preference')->first();
        abort_if($thread === null, 404);

        $settings = $this->aiSettings->channel((int) $thread->channel_account_id);
        abort_if($request->user()->is_demo || ($settings['collections'] ?? []) === [], 404);

        $history = DB::table('cv_messages')->where('conversation_id', $conversation)
            ->where(fn (Builder $query) => $query->where('direction', 'in')->orWhereIn('delivery_status', RecordWhatsAppDeliveryStatus::ACCEPTED))
            ->orderByDesc('id')->limit(12)->get(['direction', 'body'])->reverse()->values();
        abort_unless($history->isNotEmpty() && $history->last()->direction === 'in', 422);

        $draft = $compose($history, [...$settings,
            'memory' => json_decode($thread->memory ?? '{}', true, flags: JSON_THROW_ON_ERROR),
            'language_preference' => $thread->language_preference,
            'channel' => $thread->channel,
        ]);
        unset($draft['asset_collections'], $draft['asset_agent']);

        return response()->json(['draft' => $draft]);
    }

    public function update(Request $request, int $conversation, RecordConverseAudit $audit, RecordConverseNotification $notifications): RedirectResponse
    {
        $input = $request->validate([
            'mode' => ['required', Rule::in(self::MODES)],
            'status' => ['required', Rule::in(self::STATUSES)],
            'search' => ['nullable', 'string', 'max:100'],
            'channel' => ['nullable', Rule::in(self::CHANNELS)],
            'filter_status' => ['nullable', Rule::in(self::STATUSES)],
        ]);
        $data = ['mode' => $input['mode'], 'status' => $input['status']];

        DB::transaction(function () use ($request, $conversation, $data, $audit, $notifications): void {
            $thread = $this->scope($request)->where('conversations.id', $conversation)->lockForUpdate()->first();
            abort_if($thread === null, 404);

            $previous = ['mode' => $thread->mode, 'status' => $thread->status];
            if ($previous !== $data) {
                DB::table('cv_conversations')->where('id', $conversation)->update([...$data, 'updated_at' => now()]);
                if ($previous['mode'] !== 'human' && $data['mode'] === 'human') {
                    $notifications->humanHandoff($conversation, manual: true);
                }
                $audit($request->user()->id, 'conversation.updated', 'conversation', $conversation, ['from' => $previous, 'to' => $data]);
            }
        });

        Inertia::flash('success', __('workspace.conversation_updated'));

        return to_route('converse.inbox', [
            'conversation' => $conversation,
            'search' => $input['search'] ?? null,
            'channel' => $input['channel'] ?? null,
            'status' => $input['filter_status'] ?? null,
        ]);
    }

    public function reply(Request $request, int $conversation, SendConverseReply $send): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required_without_all:attachment,catalog_attachment_id', 'nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'max:'.ConverseOutboundAssets::MAX_UPLOAD_KIB, 'prohibits:catalog_attachment_id'],
            'catalog_attachment_id' => ['nullable', 'integer', 'min:1'],
            'operation_id' => ['required', 'uuid'],
        ]);

        $status = $send($request->user(), $conversation, $data['operation_id'], $data['body'] ?? '',
            $data['attachment'] ?? null, isset($data['catalog_attachment_id']) ? (int) $data['catalog_attachment_id'] : null);
        if (in_array($status, RecordWhatsAppDeliveryStatus::ACCEPTED, true)) {
            Inertia::flash('success', __('workspace.reply_accepted'));
        }

        return back(fallback: route('converse.inbox', $conversation));
    }

    public function retryAttachment(Request $request, int $conversation, int $message, SendConverseReply $send): RedirectResponse
    {
        $send->retryAttachment($request->user(), $conversation, $message);

        return to_route('converse.inbox', $conversation);
    }

    public function downloadAttachment(Request $request, int $conversation, int $message): StreamedResponse
    {
        abort_unless($this->scope($request)->where('conversations.id', $conversation)->exists(), 404);
        $asset = DB::table('cv_messages as message')->join('cv_outbound_assets as asset', 'asset.id', '=', 'message.outbound_asset_id')
            ->where('message.conversation_id', $conversation)->where('message.id', $message)
            ->first(['asset.path', 'asset.name', 'asset.mime']);
        abort_unless($asset !== null && Storage::disk('local')->exists($asset->path), 404);

        return Storage::disk('local')->download($asset->path, $asset->name, [
            'Content-Type' => $asset->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Replace the thread's tags; unknown names are created on the fly.
     */
    public function syncTags(Request $request, RecordConverseAudit $audit, int $conversation): RedirectResponse
    {
        $data = $request->validate([
            'tags' => ['present', 'array', 'max:20'],
            'tags.*' => ['required', 'string', 'min:2', 'max:60'],
        ]);
        $names = collect($data['tags'])->map(fn (string $name): string => mb_substr(trim($name), 0, 60))->filter()->unique()->values();

        $changes = DB::transaction(function () use ($request, $audit, $conversation, $names): array {
            $thread = $this->scope($request)->where('conversations.id', $conversation)->lockForUpdate()->first();
            abort_if($thread === null, 404);

            $tagIds = $names->map(function (string $name) use ($request): int {
                $id = DB::table('cv_conversation_tags')->where('name', $name)->value('id');

                return (int) ($id ?? DB::table('cv_conversation_tags')->insertGetId([
                    'name' => $name, 'created_by' => $request->user()->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]));
            });

            DB::table('cv_conversation_tag')->where('conversation_id', $conversation)
                ->whereNotIn('tag_id', $tagIds)->delete();
            foreach ($tagIds as $tagId) {
                DB::table('cv_conversation_tag')->insertOrIgnore(['conversation_id' => $conversation, 'tag_id' => $tagId]);
            }

            $previous = DB::table('cv_conversation_tag as pivot')->join('cv_conversation_tags as tag', 'tag.id', '=', 'pivot.tag_id')
                ->where('pivot.conversation_id', $conversation)->orderBy('tag.name')->pluck('tag.name')->all();
            $audit($request->user()->id, 'conversation.tags_synced', 'conversation', $conversation, ['from' => $previous, 'to' => $names->all()]);

            return ['from' => $previous, 'to' => $names->all()];
        });

        if ($changes['from'] !== $changes['to']) {
            Inertia::flash('success', __('workspace.conversation_tags_updated'));
        }

        return back(fallback: route('converse.inbox', $conversation));
    }

    /**
     * Queue one staff message for every conversation carrying all the chosen
     * tags (optionally restricted to a channel) inside the reply window.
     */
    public function broadcast(Request $request, RecordConverseAudit $audit): RedirectResponse
    {
        $data = $request->validate([
            'tags' => ['required', 'array', 'min:1', 'max:10'],
            'tags.*' => ['required', 'string', 'min:2', 'max:60'],
            'channel' => ['required', Rule::in([...self::CHANNELS, 'all'])],
            'body' => ['required', 'string', 'min:1', 'max:2000'],
        ]);

        $recipients = $this->whereAllTags($this->scope($request)
            ->where('accounts.enabled', true)->whereNull('contacts.opted_out_at')
            ->whereNotIn('conversations.status', ['resolved'])
            ->when($data['channel'] !== 'all', fn (Builder $query) => $query->where('accounts.type', $data['channel']))
            ->whereExists(fn (Builder $query) => $query->selectRaw('1')->from('cv_messages')
                ->whereColumn('cv_messages.conversation_id', 'conversations.id')
                ->where('cv_messages.direction', 'in')
                ->where('cv_messages.occurred_at', '>', now()->subDay())), $data['tags'])
            ->get(['conversations.id']);

        $ids = array_slice($recipients->pluck('id')->map(fn ($id): int => (int) $id)->all(), 0, 500);

        foreach ($ids as $id) {
            SendConverseBroadcast::dispatch($request->user()->id, $id, (string) Str::uuid(), $data['body'])->onQueue('default');
        }
        $audit($request->user()->id, 'conversation.broadcast_queued', 'conversation', 0, [
            'tags' => $data['tags'], 'channel' => $data['channel'], 'recipients' => count($ids),
        ]);
        Inertia::flash('success', __('workspace.broadcast_queued', ['count' => count($ids)]));

        return back(fallback: route('converse.inbox'));
    }

    /** @return array<int, string> */
    private function tagFilter(Request $request): array
    {
        $tags = $request->query('tags', []);
        $tags = is_string($tags) ? explode(',', $tags) : (is_array($tags) ? $tags : []);

        return collect($tags)->filter(fn ($tag): bool => is_string($tag) && mb_strlen(trim($tag)) >= 2 && mb_strlen($tag) <= 60)
            ->map(fn (string $tag): string => mb_substr(trim($tag), 0, 60))->unique()->values()->take(10)->all();
    }

    private function whereAllTags(Builder $query, array $tags): Builder
    {
        foreach ($tags as $tag) {
            $query->whereExists(fn (Builder $pivot) => $pivot->selectRaw('1')
                ->from('cv_conversation_tag as pivot')
                ->join('cv_conversation_tags as tag', 'tag.id', '=', 'pivot.tag_id')
                ->whereColumn('pivot.conversation_id', 'conversations.id')
                ->where('tag.name', $tag));
        }

        return $query;
    }

    /** @return array<int, array<int, string>> */
    private function conversationTags(array $conversationIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $conversationIds)));
        if ($ids === []) {
            return [];
        }

        return DB::table('cv_conversation_tag as pivot')->join('cv_conversation_tags as tag', 'tag.id', '=', 'pivot.tag_id')
            ->whereIn('pivot.conversation_id', $ids)->orderBy('tag.name')
            ->get(['pivot.conversation_id', 'tag.name'])
            ->groupBy('conversation_id')->map(fn ($rows) => $rows->pluck('name')->all())->all();
    }

    private function applyContactProfile(object $row, array $profile): void
    {
        $row->name = $profile['name'] ?? $row->name;
        $row->sender_photo = $profile['photo'] ?? null;
        $phone = $row->channel === 'whatsapp' ? WhatsAppRecipient::phone($row->contact_address) : null;
        $row->contact_suffix = $phone !== null ? substr($phone, -4) : null;
        $row->contact_phone = $phone;
        unset($row->contact_address);
    }

    private function scope(Request $request): Builder
    {
        return DB::table('cv_conversations as conversations')
            ->join('cv_contact_identities as identities', 'identities.id', '=', 'conversations.contact_identity_id')
            ->join('cv_contacts as contacts', 'contacts.id', '=', 'identities.contact_id')
            ->join('cv_channel_accounts as accounts', 'accounts.id', '=', 'identities.channel_account_id')
            ->where('accounts.is_demo', (bool) $request->user()->is_demo)
            ->select('conversations.id', 'conversations.mode', 'conversations.status', 'conversations.last_message_at',
                'contacts.name', 'identities.external_id as contact_address', 'accounts.type as channel', 'accounts.name as account');
    }
}
