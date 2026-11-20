<?php

namespace App\Jobs;

use App\Actions\ComposeConverseReply;
use App\Actions\ConverseAiSettings;
use App\Actions\RecordWhatsAppDeliveryStatus;
use App\Actions\RetrieveConverseKnowledge;
use App\Actions\SendConverseReply;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReplyToConverseTurn implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 80;

    public function __construct(public int $inboundMessageId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->inboundMessageId))->releaseAfter(5)->expireAfter(60)];
    }

    public function handle(ComposeConverseReply $compose, SendConverseReply $send, RetrieveConverseKnowledge $knowledge, ConverseAiSettings $configuration): void
    {
        if (config('converse.ai.auto_reply') !== true) {
            return;
        }

        $thread = DB::table('cv_messages as inbound')
            ->join('cv_conversations as thread', 'thread.id', '=', 'inbound.conversation_id')
            ->join('cv_contact_identities as identity', 'identity.id', '=', 'thread.contact_identity_id')
            ->join('cv_channel_accounts as account', 'account.id', '=', 'identity.channel_account_id')
            ->join('cv_contacts as contact', 'contact.id', '=', 'identity.contact_id')
            ->where('inbound.id', $this->inboundMessageId)->where('inbound.direction', 'in')
            ->where('thread.mode', 'ai')->whereIn('thread.status', ['open', 'pending'])
            ->where('account.is_demo', false)->where('account.enabled', true)->whereNull('contact.opted_out_at')
            ->whereBetween('inbound.occurred_at', [now()->subHours(24), now()])
            ->first(['thread.id', 'account.id as account_id', 'inbound.occurred_at', 'thread.memory', 'contact.language_preference', 'account.type as channel']);

        if ($thread === null) {
            return;
        }

        $settings = $configuration->channel((int) $thread->account_id);
        if (($settings['enabled'] ?? false) !== true
            || DB::table('cv_ai_turns')->where('inbound_message_id', $this->inboundMessageId)->exists()) {
            return;
        }

        $latestInbound = DB::table('cv_messages')->where('conversation_id', $thread->id)->where('direction', 'in')
            ->orderByDesc('occurred_at')->orderByDesc('id')->value('id');
        if ((int) $latestInbound !== $this->inboundMessageId) {
            return;
        }

        $history = DB::table('cv_messages')->where('conversation_id', $thread->id)->where(fn ($query) => $query->where('occurred_at', '<', $thread->occurred_at)
            ->orWhere(fn ($tie) => $tie->where('occurred_at', $thread->occurred_at)->where('id', '<=', $latestInbound)))
            ->where(fn ($query) => $query->where('direction', 'in')->orWhereIn('delivery_status', RecordWhatsAppDeliveryStatus::ACCEPTED))
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(12)->get(['direction', 'body'])->reverse()->values();
        $reply = $compose($history, [...$settings,
            'memory' => json_decode($thread->memory ?? '{}', true, flags: JSON_THROW_ON_ERROR),
            'language_preference' => $thread->language_preference,
            'channel' => $thread->channel,
        ]);
        if ($reply['source_ids'] !== []
            && ! $knowledge($settings['collections'] ?? [], '', entryId: $reply['source_ids'][0])->count()) {
            return;
        }

        $operationId = (string) Str::uuid();
        $claimed = DB::table('cv_ai_turns')->insertOrIgnore([
            'inbound_message_id' => $this->inboundMessageId, 'operation_id' => $operationId,
            'language' => $reply['language'], 'intent' => $reply['intent'], 'decision' => $reply['decision'],
            'source_ids' => json_encode($reply['source_ids'], JSON_THROW_ON_ERROR), 'created_at' => now(),
            'provider' => $reply['provider'] ?? null, 'model' => $reply['model'] ?? null,
            'fallback' => $reply['fallback'] ?? false,
        ]);

        if ($claimed) {
            $send->automatic((int) $thread->id, $this->inboundMessageId, $operationId, $reply['body'], $reply['decision'] === 'handoff',
                $reply['asset_id'] ?? null, $reply['asset_collections'] ?? [], $reply['asset_agent'] ?? null);
        }
    }
}
