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
use App\Enums\WorkspacePermission;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        $scope = $this->scope($request);

        $thread = $conversation === null ? null : (clone $scope)->where('conversations.id', $conversation)->addSelect('accounts.id as channel_account_id', 'conversations.memory', 'contacts.language_preference')->first();
        abort_if($conversation !== null && $thread === null, 404);

        $conversations = (clone $scope)
            ->when($status !== '', fn (Builder $query) => $query->where('conversations.status', $status))
            ->when($channel !== '', fn (Builder $query) => $query->where('accounts.type', $channel))
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                $query->whereLike('contacts.name', "%{$search}%")
                    ->orWhereLike('accounts.name', "%{$search}%")
                    ->orWhereExists(fn (Builder $messages) => $messages->selectRaw('1')->from('cv_messages')
                        ->whereColumn('cv_messages.conversation_id', 'conversations.id')
                        ->whereLike('cv_messages.body', "%{$search}%"));
            }))
            ->orderByDesc('conversations.last_message_at')->orderByDesc('conversations.id')
            ->simplePaginate(25, pageName: 'inbox_page')->withQueryString();
        foreach ($conversations->items() as $row) {
            $row->last_message_at = Carbon::parse($row->last_message_at)->toISOString();
        }

        $messages = $thread === null ? null : DB::table('cv_messages as message')
            ->leftJoin('cv_outbound_assets as asset', 'message.outbound_asset_id', '=', 'asset.id')
            ->where('message.conversation_id', $thread->id)
            ->orderByDesc('message.occurred_at')->orderByDesc('message.id')
            ->select('message.id', 'message.direction', 'message.sender_type', 'message.body', 'message.occurred_at',
                'message.delivery_status', 'message.attachment_status', 'asset.name as attachment_name')
            ->simplePaginate(50, pageName: 'thread_page')->withQueryString();
        foreach ($messages?->items() ?? [] as $row) {
            $row->occurred_at = Carbon::parse($row->occurred_at)->toISOString();
        }

        if ($thread && $thread->channel === 'messenger' && ! $request->user()->is_demo) {
            $senderProfile = $profile((int) $thread->id);
            $thread->name = $senderProfile['name'] ?? $thread->name;
            $thread->sender_photo = $senderProfile['photo'] ?? null;
        }

        $canManage = $request->user()->can(WorkspacePermission::ManageConverseInbox->value);
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
            'filters' => ['search' => $search, 'channel' => $channel, 'status' => $status],
            'channels' => self::CHANNELS,
            'modes' => self::MODES,
            'statuses' => self::STATUSES,
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

    private function scope(Request $request): Builder
    {
        return DB::table('cv_conversations as conversations')
            ->join('cv_contact_identities as identities', 'identities.id', '=', 'conversations.contact_identity_id')
            ->join('cv_contacts as contacts', 'contacts.id', '=', 'identities.contact_id')
            ->join('cv_channel_accounts as accounts', 'accounts.id', '=', 'identities.channel_account_id')
            ->where('accounts.is_demo', (bool) $request->user()->is_demo)
            ->select('conversations.id', 'conversations.mode', 'conversations.status', 'conversations.last_message_at',
                'contacts.name', 'accounts.type as channel', 'accounts.name as account');
    }
}
