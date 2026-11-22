<?php

namespace App\Jobs;

use App\Actions\ComposeConverseReply;
use App\Actions\ConverseAiSettings;
use App\Actions\ConverseReviewQueue;
use App\Actions\RecordWhatsAppDeliveryStatus;
use App\Actions\RetrieveConverseKnowledge;
use App\Actions\SendConverseReply;
use App\Actions\TagConverseAiConversation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

class ReplyToConverseTurn implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 80;

    public function __construct(public int $inboundMessageId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->inboundMessageId))->releaseAfter(5)->expireAfter(90)];
    }

    public function handle(ComposeConverseReply $compose, SendConverseReply $send, RetrieveConverseKnowledge $knowledge, ConverseAiSettings $configuration, TagConverseAiConversation $tag, ConverseReviewQueue $review): void
    {
        $thread = DB::table('cv_messages as inbound')
            ->join('cv_conversations as thread', 'thread.id', '=', 'inbound.conversation_id')
            ->join('cv_contact_identities as identity', 'identity.id', '=', 'thread.contact_identity_id')
            ->join('cv_channel_accounts as account', 'account.id', '=', 'identity.channel_account_id')
            ->join('cv_contacts as contact', 'contact.id', '=', 'identity.contact_id')
            ->where('inbound.id', $this->inboundMessageId)->where('inbound.direction', 'in')
            ->where('thread.mode', 'ai')->whereIn('thread.status', ['open', 'pending'])
            ->where('account.is_demo', false)->where('account.enabled', true)->whereNull('contact.opted_out_at')
            ->whereBetween('inbound.occurred_at', [now()->subHours(24), now()])
            ->first(['thread.id', 'account.id as account_id', 'inbound.occurred_at', 'thread.memory', 'contact.language_preference',
                'account.type as channel', 'thread.knowledge_scope_mode', 'thread.knowledge_collection_ids']);

        if ($thread === null) {
            return;
        }

        $settings = $configuration->channel((int) $thread->account_id);
        if (($settings['enabled'] ?? false) !== true || ! $send->autoReplyEnabled((int) $thread->account_id)
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
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(12)->get(['direction', 'body', 'outbound_asset_id'])->reverse()->values();
        $reply = $compose($history, [...$settings,
            'conversation_id' => (int) $thread->id,
            'memory' => json_decode($thread->memory ?? '{}', true, flags: JSON_THROW_ON_ERROR),
            'language_preference' => $thread->language_preference,
            'channel' => $thread->channel,
            'knowledge_scope_mode' => $thread->knowledge_scope_mode,
            'knowledge_collection_ids' => json_decode($thread->knowledge_collection_ids ?? '[]', true, flags: JSON_THROW_ON_ERROR),
        ]);
        $scope = $reply['scope'];
        foreach ($reply['source_ids'] as $source) {
            if ($knowledge($scope['collection_ids'], '', entryId: $source,
                subjectId: $scope['restrict_subject'] ? $scope['subject_id'] : null)->isEmpty()) {
                return;
            }
        }

        $operationId = (string) Str::uuid();
        $decision = DB::transaction(function () use ($thread, $operationId, $reply, $scope, $tag, $history, $review, $send): ?string {
            $conversation = DB::table('cv_conversations')->where('id', $thread->id)->lockForUpdate()
                ->first(['mode', 'status', 'knowledge_scope_mode', 'knowledge_collection_ids']);
            $latest = DB::table('cv_messages')->where('conversation_id', $thread->id)->where('direction', 'in')
                ->orderByDesc('occurred_at')->orderByDesc('id')->value('id');
            if ($conversation?->mode !== 'ai' || ! in_array($conversation->status, ['open', 'pending'], true)
                || (int) $latest !== $this->inboundMessageId || ! $send->autoReplyEnabled((int) $thread->account_id)) {
                return 'ignored';
            }
            if ($conversation->knowledge_scope_mode !== $thread->knowledge_scope_mode
                || $conversation->knowledge_collection_ids !== $thread->knowledge_collection_ids) {
                return null;
            }
            $decision = $reply['decision'] === 'handoff' ? 'queued' : 'send';
            $claimed = DB::table('cv_ai_turns')->insertOrIgnore([
                'inbound_message_id' => $this->inboundMessageId, 'operation_id' => $operationId,
                'language' => $reply['language'], 'intent' => $reply['intent'], 'decision' => $decision,
                'source_ids' => json_encode($reply['source_ids'], JSON_THROW_ON_ERROR),
                'knowledge_scope' => json_encode($scope, JSON_THROW_ON_ERROR),
                'created_at' => now(), 'provider' => $reply['provider'] ?? null, 'model' => $reply['model'] ?? null,
                'fallback' => $reply['fallback'] ?? false,
            ]);
            if ($claimed) {
                if ($conversation->knowledge_scope_mode === 'automatic') {
                    $previous = json_decode($conversation->knowledge_collection_ids ?? '[]', true, flags: JSON_THROW_ON_ERROR);
                    $ids = array_values(array_unique([...$previous, ...$scope['connected_collection_ids']]));
                    if ($ids !== $previous) {
                        DB::table('cv_conversations')->where('id', $thread->id)->update([
                            'knowledge_collection_ids' => json_encode($ids, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                        ]);
                    }
                }
                $tag((int) $thread->id, $history->last()->body, $scope);
                if ($decision === 'queued'
                    && $review->record((int) $thread->id, $this->inboundMessageId, $thread->occurred_at, 'unanswered')) {
                    DB::table('cv_ai_turns')->where('inbound_message_id', $this->inboundMessageId)->update(['decision' => 'handoff']);
                    $decision = 'handoff';
                }
            }

            return $claimed ? $decision : 'ignored';
        }, attempts: 3);
        if ($decision === null) {
            $this->release(1);

            return;
        }
        if (! in_array($decision, ['send', 'handoff'], true)) {
            return;
        }
        foreach ($reply['messages'] ?? [$reply['body']] as $index => $body) {
            $partId = $index === 0 ? $operationId : Uuid::uuid5($operationId, (string) $index)->toString();
            if (! $send->automatic((int) $thread->id, $this->inboundMessageId, $partId, $body, $decision === 'handoff',
                $index === 0 ? ($reply['asset_id'] ?? null) : null, $reply['asset_collections'] ?? [], $reply['asset_agent'] ?? null,
                $scope['collection_ids'])) {
                break;
            }
        }
    }
}
