<?php

namespace App\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ConverseReviewQueue
{
    public function __construct(private RecordConverseNotification $notifications, private WorkspaceSettings $settings) {}

    /** Record a question while holding the conversation row lock. */
    public function record(int $conversation, int $inbound, string $occurredAt, string $reason, ?int $aiMessage = null): bool
    {
        $date = CarbonImmutable::parse($occurredAt, 'UTC')->setTimezone('Asia/Dhaka')->toDateString();
        $created = DB::table('cv_ai_review_tasks')->insertOrIgnore([
            'conversation_id' => $conversation,
            'inbound_message_id' => $inbound,
            'ai_message_id' => $aiMessage,
            'failure_date' => $date,
            'reason' => $reason,
            'created_at' => now(),
        ]);
        if (! $created || $reason !== 'unanswered') {
            return false;
        }

        $limit = (int) $this->settings->converse()['daily_ai_failure_limit'];
        $failures = DB::table('cv_ai_review_tasks')->where('conversation_id', $conversation)
            ->where('failure_date', $date)->where('reason', 'unanswered');
        if ($failures->count() < $limit || (clone $failures)->whereNotNull('notification_id')->exists()) {
            return false;
        }

        $notification = $this->notifications->humanHandoff($conversation);
        DB::table('cv_ai_review_tasks')->where('inbound_message_id', $inbound)->update(['notification_id' => $notification]);

        return true;
    }

    public function pending(Builder $query): Builder
    {
        return $query->whereNull('task.closed_at')->where(fn (Builder $reply) => $reply
            ->whereNull('reply.delivery_status')->orWhereNotIn('reply.delivery_status', RecordWhatsAppDeliveryStatus::ACCEPTED))
            ->where(fn (Builder $reply) => $reply->whereNull('ai_reply.delivery_status')
                ->orWhereNotIn('ai_reply.delivery_status', RecordWhatsAppDeliveryStatus::ACCEPTED));
    }
}
