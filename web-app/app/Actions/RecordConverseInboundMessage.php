<?php

namespace App\Actions;

use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RecordConverseInboundMessage
{
    public function __construct(private RememberConverseConversation $memory, private RecordConverseNotification $notifications, private WorkspaceSettings $settings, private ConverseAiSettings $aiSettings) {}

    /**
     * Record a verified, normalized provider message within an existing channel account.
     */
    public function __invoke(int $accountId, string $senderId, string $messageId, string $body, CarbonInterface $occurredAt, ?string $name = null, ?string $whatsappUserId = null): ?int
    {
        return DB::transaction(function () use ($accountId, $senderId, $messageId, $body, $occurredAt, $name, $whatsappUserId): ?int {
            $account = DB::table('cv_channel_accounts')->where('id', $accountId)->lockForUpdate()->firstOrFail();
            if (! $account->enabled) {
                return null;
            }

            $identities = DB::table('cv_contact_identities')->where('channel_account_id', $accountId);
            $identity = (clone $identities)->where('external_id', $senderId)->first();
            if ($identity === null && $account->type === 'whatsapp' && $whatsappUserId !== null) {
                $identity = (clone $identities)->where(fn (Builder $query) => $query
                    ->where('whatsapp_user_id', $whatsappUserId)->orWhere('external_id', $whatsappUserId))->first();
            }
            $now = now();

            if ($identity === null) {
                $contactId = DB::table('cv_contacts')->insertGetId([
                    'name' => $name,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $identityId = DB::table('cv_contact_identities')->insertGetId([
                    'contact_id' => $contactId,
                    'channel_account_id' => $accountId,
                    'external_id' => $senderId,
                ]);
            } else {
                $identityId = $identity->id;
                $contactId = $identity->contact_id;
                if ($name !== null) {
                    DB::table('cv_contacts')->where('id', $contactId)->whereNull('name')->update(['name' => $name, 'updated_at' => $now]);
                }
            }

            $conversationId = DB::table('cv_conversations')->where('contact_identity_id', $identityId)->value('id')
                ?? DB::table('cv_conversations')->insertGetId([
                    'contact_identity_id' => $identityId,
                    // Workspace settings decide how new conversations start out.
                    'mode' => in_array($mode = $this->settings->converse()['new_conversation_mode'] ?? 'ai', ['ai', 'human', 'paused'], true) ? $mode : 'ai',
                    'last_message_at' => $occurredAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

            $existing = DB::table('cv_messages')->where('conversation_id', $conversationId)
                ->where('external_id', $messageId)->value('id');

            if ($existing !== null) {
                return (int) $existing;
            }

            if ($account->type === 'whatsapp' && ($identity?->whatsapp_address_at === null
                || Carbon::parse($identity->whatsapp_address_at)->lte($occurredAt))) {
                $address = ['whatsapp_reply_address' => $senderId, 'whatsapp_address_at' => $occurredAt];
                if ($whatsappUserId !== null && ! (clone $identities)->where('id', '!=', $identityId)
                    ->where(fn (Builder $query) => $query->where('external_id', $whatsappUserId)
                        ->orWhere('whatsapp_user_id', $whatsappUserId))->exists()) {
                    $address['whatsapp_user_id'] = $whatsappUserId;
                }
                (clone $identities)->where('id', $identityId)->update($address);
            }

            $id = DB::table('cv_messages')->insertGetId([
                'conversation_id' => $conversationId,
                'external_id' => $messageId,
                'direction' => 'in',
                'sender_type' => 'customer',
                'body' => $body,
                'occurred_at' => $occurredAt,
            ]);

            $conversation = DB::table('cv_conversations')->where('id', $conversationId);
            $thread = $conversation->lockForUpdate()->first(['mode', 'status', 'last_message_at', 'resume_ai_on_inbound']);
            (clone $conversation)->where('last_message_at', '<', $occurredAt)
                ->update(['last_message_at' => $occurredAt, 'updated_at' => $now]);

            $this->memory->record((int) $conversationId, (int) $contactId, $body, $occurredAt);

            $normalized = mb_strtolower(trim(preg_replace('/[\s\p{P}]+/u', ' ', $body) ?? $body));
            if (in_array($normalized, ['stop', 'unsubscribe', 'বন্ধ করুন', 'বন্ধ করো', 'bondho koro', 'bondho korun'], true)) {
                DB::table('cv_contacts')->where('id', $contactId)->whereNull('opted_out_at')->update([
                    'opted_out_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $resumed = $thread->resume_ai_on_inbound && $thread->mode === 'human' && $thread->status === 'pending'
                && ! $account->is_demo && trim($body) !== '' && Carbon::parse($thread->last_message_at)->lte($occurredAt)
                && $this->settings->converse()['auto_reply'] && ($this->aiSettings->channel($accountId)['enabled'] ?? false)
                && DB::table('cv_contacts')->where('id', $contactId)->whereNull('opted_out_at')->exists();
            if ($resumed) {
                $conversation->update(['mode' => 'ai', 'resume_ai_on_inbound' => false, 'updated_at' => $now]);
            }

            if ($resumed || $thread->mode === 'ai') {
                $this->notifications->inboundWhileAi((int) $conversationId);
            }

            return (int) $id;
        }, attempts: 3);
    }
}
