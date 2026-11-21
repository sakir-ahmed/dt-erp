<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SendConverseReply
{
    public function __construct(private ConverseAiSettings $aiSettings, private RecordConverseNotification $notifications, private ConverseOutboundAssets $assets, private SendConverseMedia $media, private WorkspaceSettings $workspaceSettings) {}

    public function __invoke(User $user, int $conversation, string $operationId, string $body, ?UploadedFile $file = null, ?int $catalogAsset = null): string
    {
        $attempt = DB::transaction(function () use ($user, $conversation, $operationId, $body, $file, $catalogAsset): object {
            $thread = $this->thread($user, $conversation);
            $existing = DB::table('cv_messages')->where('conversation_id', $conversation)
                ->where('operation_id', $operationId)->first();

            if ($existing !== null) {
                $digest = $existing->outbound_asset_id === null ? null
                    : $this->assets->forConversation((int) $existing->outbound_asset_id, $conversation, $user->id)?->digest;
                if ($existing->body !== $body || (int) $existing->sent_by !== $user->id
                    || ($catalogAsset !== null ? (int) $existing->outbound_asset_id !== $catalogAsset
                        : ($existing->outbound_asset_id !== null && $digest === null)
                            || $digest !== ($file ? hash_file('sha256', $file->getPathname()) : null))) {
                    throw ValidationException::withMessages(['body' => __('workspace.reply_operation_conflict')]);
                }

                return (object) ['id' => null, 'status' => $existing->delivery_status];
            }

            if ($reason = $this->blockedReason($thread, $conversation)) {
                throw ValidationException::withMessages(['body' => __('workspace.'.$reason)]);
            }

            $asset = $file ? $this->assets->store($file, $user->id, conversation: $conversation) : null;
            if ($catalogAsset !== null) {
                $asset = $this->assets->catalog($this->aiSettings->channel((int) $thread->channel_account_id)['collections'] ?? [])
                    ->where('asset.id', $catalogAsset)->first(['asset.*']);
                if ($asset === null) {
                    throw ValidationException::withMessages(['catalog_attachment_id' => __('workspace.converse_attachment_invalid')]);
                }
            }
            $id = DB::table('cv_messages')->insertGetId([
                'conversation_id' => $conversation,
                'operation_id' => $operationId,
                'delivery_status' => 'sending',
                'sent_by' => $user->id,
                'outbound_asset_id' => $asset?->id,
                'direction' => 'out',
                'sender_type' => 'human',
                'body' => $body,
                'occurred_at' => now(),
            ]);

            return (object) ['id' => $id, 'status' => 'sending', 'asset' => $asset];
        }, attempts: 3);

        if ($attempt->id === null) {
            return $attempt->status;
        }

        // The committed claim prevents replay after a timeout or a process interruption.
        return DB::transaction(function () use ($attempt, $user, $conversation, $body, $operationId): string {
            $thread = $this->thread($user, $conversation);
            $status = 'failed';
            $externalId = $attachmentStatus = $attachmentId = null;

            $asset = $attempt->asset;
            if ($asset?->collection_id !== null) {
                $asset = $this->assets->catalog($this->aiSettings->channel((int) $thread->channel_account_id)['collections'] ?? [])
                    ->where('asset.id', $asset->id)->lockForUpdate()->first(['asset.*']);
            }
            if ($this->blockedReason($thread, $conversation) === null && ($attempt->asset === null || $asset !== null)) {
                try {
                    [$status, $externalId, $attachmentStatus, $attachmentId] = $this->send($thread, $body, $operationId, $asset);
                } catch (ConnectionException) {
                    $status = 'unknown';
                } catch (DecryptException) {
                    $status = 'failed';
                }
            }

            DB::table('cv_messages')->where('id', $attempt->id)->where('delivery_status', 'sending')->update([
                'delivery_status' => $status,
                'external_id' => $externalId,
                'attachment_status' => $attachmentStatus, 'attachment_external_id' => $attachmentId,
            ]);

            if ($status === 'accepted') {
                $now = now();
                DB::table('cv_conversations')->where('id', $conversation)->where('last_message_at', '<', $now)
                    ->update(['last_message_at' => $now, 'updated_at' => $now]);
            }

            return $status;
        });
    }

    public function automatic(int $conversation, int $inboundId, string $operationId, string $body, bool $handoff, ?int $assetId = null, array $catalogScope = [], ?string $catalogAgent = null): void
    {
        $claim = DB::transaction(function () use ($conversation, $inboundId, $operationId, $body, $assetId): ?int {
            $thread = $this->thread(null, $conversation);
            if (! $this->canReplyAutomatically($thread, $conversation, $inboundId)
                || DB::table('cv_messages')->where('conversation_id', $conversation)->where('operation_id', $operationId)->exists()) {
                return null;
            }

            return DB::table('cv_messages')->insertGetId([
                'conversation_id' => $conversation, 'operation_id' => $operationId,
                'outbound_asset_id' => $assetId,
                'direction' => 'out', 'sender_type' => 'ai', 'delivery_status' => 'sending',
                'body' => $body, 'occurred_at' => now(),
            ]);
        });

        DB::transaction(function () use ($claim, $conversation, $inboundId, $body, $handoff, $operationId, $assetId, $catalogScope, $catalogAgent): void {
            $thread = $this->thread(null, $conversation);
            $eligible = $this->canReplyAutomatically($thread, $conversation, $inboundId);
            $status = 'failed';
            $externalId = $attachmentStatus = $attachmentId = null;
            $asset = null;
            if ($assetId !== null && $eligible) {
                $configuration = $this->aiSettings->get();
                $channel = $configuration['channels'][$thread->channel_account_id] ?? [];
                $agent = $configuration['agents'][$catalogAgent] ?? null;
                if (($channel['agent'] ?? $catalogAgent) === $catalogAgent && $agent !== null && ($agent['enabled'] ?? true)) {
                    $current = $channel['collections'] ?? [];
                    $asset = $this->assets->catalog(array_intersect($catalogScope, $current, $agent['collections'] ?? $current))
                        ->where('asset.id', $assetId)->lockForUpdate()->first(['asset.*']);
                }
                $eligible = $asset !== null && ! $handoff;
            }
            if ($claim !== null && $eligible) {
                try {
                    [$status, $externalId, $attachmentStatus, $attachmentId] = $this->send($thread, $body, $operationId, $asset);
                } catch (ConnectionException) {
                    $status = 'unknown';
                } catch (DecryptException) {
                    $status = 'failed';
                }
            }

            if ($claim !== null) {
                DB::table('cv_messages')->where('id', $claim)->where('delivery_status', 'sending')->update([
                    'delivery_status' => $status, 'external_id' => $externalId,
                ]);
            }

            if ($status === 'accepted') {
                if (! $handoff) {
                    $this->notifications->aiReplyAccepted($conversation);
                }
                $now = now();
                DB::table('cv_conversations')->where('id', $conversation)->where('last_message_at', '<', $now)
                    ->update(['last_message_at' => $now, 'updated_at' => $now]);
            }

            $latest = DB::table('cv_messages')->where('conversation_id', $conversation)->where('direction', 'in')
                ->orderByDesc('occurred_at')->orderByDesc('id')->value('id');
            if ($this->autoReplyEnabled((int) $thread->channel_account_id) && $thread->enabled && $thread->opted_out_at === null
                && $thread->mode === 'ai' && in_array($thread->status, ['open', 'pending'], true)
                && (int) $latest === $inboundId && ($handoff || ($claim !== null && $status !== 'accepted'))) {
                DB::table('cv_conversations')->where('id', $conversation)->update([
                    'mode' => 'human', 'status' => 'pending', 'updated_at' => now(),
                ]);
                $this->notifications->humanHandoff($conversation);
            }
        });
    }

    public function retryAttachment(User $user, int $conversation, int $message): void
    {
        $claim = DB::transaction(function () use ($user, $conversation, $message): ?object {
            $thread = $this->thread($user, $conversation);
            if ($this->blockedReason($thread, $conversation) !== null) {
                throw ValidationException::withMessages(['attachment' => __('workspace.converse_retry_unavailable')]);
            }

            $record = DB::table('cv_messages')->where('conversation_id', $conversation)->where('id', $message)
                ->where('direction', 'out')->where('delivery_status', 'partial')->where('attachment_status', 'failed')
                ->whereNotNull('external_id')->whereNotNull('outbound_asset_id')->lockForUpdate()->first();
            if ($record) {
                DB::table('cv_messages')->where('id', $record->id)->update(['attachment_status' => 'sending']);
            }

            return $record;
        });
        if ($claim === null) {
            return;
        }

        DB::transaction(function () use ($user, $conversation, $claim): void {
            $thread = $this->thread($user, $conversation);
            $asset = $this->assets->forConversation((int) $claim->outbound_asset_id, $conversation);
            $asset ??= $this->assets->catalog($this->aiSettings->channel((int) $thread->channel_account_id)['collections'] ?? [])
                ->where('asset.id', $claim->outbound_asset_id)->lockForUpdate()->first(['asset.*']);
            $status = 'failed';
            $id = null;

            if ($asset !== null && $this->blockedReason($thread, $conversation) === null) {
                try {
                    [$status, $id] = $this->send($thread, '', (string) Str::uuid(), $asset);
                } catch (DecryptException) {
                    $status = 'failed';
                }
            }

            DB::table('cv_messages')->where('id', $claim->id)->where('attachment_status', 'sending')->update([
                'delivery_status' => $status === 'accepted' ? 'accepted' : 'partial',
                'attachment_status' => $status,
                'attachment_external_id' => $id,
            ]);
        });
    }

    private function canReplyAutomatically(object $thread, int $conversation, int $inboundId): bool
    {
        return $this->autoReplyEnabled((int) $thread->channel_account_id)
            && $this->blockedReason($thread, $conversation, automatic: true) === null
            && (int) DB::table('cv_messages')->where('conversation_id', $conversation)->where('direction', 'in')
                ->orderByDesc('occurred_at')->orderByDesc('id')->value('id') === $inboundId;
    }

    private function autoReplyEnabled(int $accountId): bool
    {
        $settings = $this->workspaceSettings->converse();
        if ($settings['auto_reply'] !== true) {
            return false;
        }

        // A channel account configured in the AI playground overrides the
        // per-type default from workspace settings.
        $override = $this->aiSettings->channel($accountId)['enabled'] ?? null;
        if ($override !== null) {
            return $override === true;
        }

        $type = DB::table('cv_channel_accounts')->where('id', $accountId)->value('type');

        return ($settings['channel_types'][$type] ?? false) === true;
    }

    public function unavailableReason(User $user, int $conversation): ?string
    {
        return $this->blockedReason($this->thread($user, $conversation, lock: false), $conversation);
    }

    private function thread(?User $user, int $id, bool $lock = true): object
    {
        $query = DB::table('cv_conversations as thread')
            ->join('cv_contact_identities as identity', 'identity.id', '=', 'thread.contact_identity_id')
            ->join('cv_contacts as contact', 'contact.id', '=', 'identity.contact_id')
            ->join('cv_channel_accounts as account', 'account.id', '=', 'identity.channel_account_id')
            ->where('thread.id', $id)->where('account.is_demo', (bool) $user?->is_demo)
            ->select('thread.mode', 'thread.status', 'account.enabled', 'account.is_demo', 'account.type', 'account.send_token',
                'account.id as channel_account_id', 'account.external_id as account_id', 'contact.opted_out_at')
            ->selectRaw('COALESCE(identity.whatsapp_reply_address, identity.external_id) AS recipient_id');
        $thread = ($lock ? $query->lockForUpdate() : $query)->first();

        abort_if($thread === null, 404);

        return $thread;
    }

    private function blockedReason(object $thread, int $conversation, bool $automatic = false): ?string
    {
        if ($thread->is_demo || ! $thread->enabled || ! $thread->send_token
            || config('converse.outbound_enabled') !== true || ! in_array($thread->type, ['messenger', 'whatsapp'], true)) {
            return 'reply_channel_unavailable';
        }

        if ($thread->type === 'whatsapp' && WhatsAppRecipient::phone($thread->recipient_id) === null
            && WhatsAppRecipient::businessId($thread->recipient_id) === null) {
            return 'reply_whatsapp_invalid_recipient';
        }

        if ($thread->mode !== ($automatic ? 'ai' : 'human') || $thread->status === 'resolved'
            || ($automatic && $thread->status === 'snoozed')) {
            return 'reply_requires_human';
        }

        if ($thread->opted_out_at !== null) {
            return 'reply_opted_out';
        }

        $now = now();
        $latest = DB::table('cv_messages')->where('conversation_id', $conversation)->where('direction', 'in')
            ->where('occurred_at', '<=', $now)->max('occurred_at');

        if ($latest === null || Carbon::parse($latest)->addHours(24)->lte($now)) {
            return $thread->type === 'whatsapp' ? 'reply_whatsapp_window_closed' : 'reply_window_closed';
        }

        return null;
    }

    /** @return array{string, ?string, ?string, ?string} */
    private function send(object $thread, string $body, string $operationId, ?object $asset): array
    {
        $whatsapp = $thread->type === 'whatsapp';
        $base = 'https://graph.facebook.com/'.rawurlencode(config('converse.graph_version')).'/'.rawurlencode($thread->account_id);
        $token = Crypt::decryptString($thread->send_token);
        $request = fn (int $timeout) => Http::acceptJson()->withoutRedirecting()->withToken($token)
            ->connectTimeout(3)->timeout($timeout);
        $envelope = $whatsapp
            ? [
                'messaging_product' => 'whatsapp', 'recipient_type' => 'individual',
                ...(WhatsAppRecipient::phone($thread->recipient_id) !== null
                    ? ['to' => '+'.WhatsAppRecipient::phone($thread->recipient_id)]
                    : ['recipient' => $thread->recipient_id]),
                'biz_opaque_callback_data' => $operationId,
            ]
            : ['messaging_type' => 'RESPONSE', 'recipient' => ['id' => $thread->recipient_id]];
        $post = function (array $payload) use ($request, $base, $whatsapp, $thread): array {
            try {
                $response = $request(8)->asJson()->post($base.'/messages', $payload);
            } catch (ConnectionException) {
                Log::channel('converse')->warning('send network failure', [
                    'channel' => $thread->type, 'account_id' => $thread->account_id,
                ]);

                return ['unknown', null];
            }
            if ($response->clientError()) {
                Log::channel('converse')->warning('send rejected by provider', [
                    'channel' => $thread->type, 'account_id' => $thread->account_id,
                    'status' => $response->status(),
                    'error' => $response->json('error.message'),
                    'code' => $response->json('error.code'),
                    'body' => mb_substr((string) $response->body(), 0, 500),
                ]);

                return ['failed', null];
            }
            $id = $response->successful() ? $response->json($whatsapp ? 'messages.0.id' : 'message_id') : null;
            if ($id === null || (is_string($id) && ($id === '' || strlen($id) > 255))) {
                Log::channel('converse')->warning('send outcome unknown', [
                    'channel' => $thread->type, 'account_id' => $thread->account_id,
                    'status' => $response->status(),
                    'body' => mb_substr((string) $response->body(), 0, 500),
                ]);
                $id = null;
            }

            return is_string($id) && $id !== '' ? ['accepted', $id] : ['unknown', null];
        };

        $mediaId = $asset ? $this->media->upload($request(20), $base, $whatsapp, $asset) : null;
        if ($asset && $mediaId === null) {
            return ['failed', null, 'failed', null];
        }

        if ($asset && $whatsapp && mb_strlen($body) <= 1024) {
            $type = $asset->mime === 'application/pdf' ? 'document' : 'image';
            [$status, $id] = $post([...$envelope, 'type' => $type, $type => [
                'id' => $mediaId, ...($body === '' ? [] : ['caption' => $body]),
                ...($type === 'document' ? ['filename' => $asset->name] : []),
            ]]);

            return [$status, $id, $status, null];
        }

        $status = 'accepted';
        $textId = null;
        if ($body !== '') {
            [$status, $textId] = $post([...$envelope, ...($whatsapp
                ? ['type' => 'text', 'text' => ['body' => $body]]
                : ['message' => ['text' => $body]])]);
        }
        if (! $asset || $status !== 'accepted') {
            return [$status, $textId, null, null];
        }

        if ($whatsapp) {
            // A second provider message must not reuse the caption's operation ID.
            unset($envelope['biz_opaque_callback_data']);
            $type = $asset->mime === 'application/pdf' ? 'document' : 'image';
            $payload = [...$envelope, 'type' => $type, $type => [
                'id' => $mediaId, ...($type === 'document' ? ['filename' => $asset->name] : []),
            ]];
        } else {
            $payload = [...$envelope, 'message' => ['attachment' => [
                'type' => $asset->mime === 'application/pdf' ? 'file' : 'image',
                'payload' => ['attachment_id' => $mediaId],
            ]]];
        }
        [$attachmentStatus, $attachmentId] = $post($payload);

        return [$textId && $attachmentStatus !== 'accepted' ? 'partial' : $attachmentStatus,
            $textId ?? $attachmentId, $attachmentStatus, $attachmentId];
    }
}
