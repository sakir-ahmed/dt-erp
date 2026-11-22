<?php

namespace App\Http\Controllers;

use App\Actions\ConverseReviewQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConverseNotificationController extends Controller
{
    public function __invoke(Request $request, ConverseReviewQueue $queue): JsonResponse
    {
        $data = $request->validate(['after' => ['sometimes', 'integer', 'min:0']]);
        $stream = $this->stream($request);
        $pending = (clone $stream)->whereIn('event.type', ['human_handoff', 'manual_handoff'])
            ->leftJoin('cv_ai_review_tasks as task', 'task.notification_id', '=', 'event.id')
            ->leftJoin('cv_messages as reply', 'reply.id', '=', 'task.reply_message_id')
            ->leftJoin('cv_messages as ai_reply', 'ai_reply.id', '=', 'task.ai_message_id')
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $legacy) => $legacy->whereNull('task.id')->where('thread.mode', 'human')->where('thread.status', '!=', 'resolved'))
                ->orWhere(fn (Builder $task) => $queue->pending($task->whereNotNull('task.id'))))
            ->whereNotExists(fn (Builder $ack) => $ack->from('cv_notification_acknowledgements')
                ->whereColumn('notification_id', 'event.id')->where('user_id', $request->user()->id))
            ->orderByDesc('event.id')->limit(20)->get(['event.id', 'event.type', 'event.conversation_id', 'task.id as task_id']);
        if (! isset($data['after'])) {
            return response()->json(['cursor' => (int) $stream->max('event.id'), 'events' => [], 'handoffs' => $pending]);
        }

        $events = $stream->where('event.id', '>', $data['after'])->orderBy('event.id')->limit(50)
            ->get(['event.id', 'event.type']);

        return response()->json([
            'cursor' => (int) ($events->last()->id ?? $data['after']),
            'events' => $events,
            'handoffs' => $pending,
        ]);
    }

    public function acknowledge(Request $request, int $notification): JsonResponse
    {
        abort_unless($this->stream($request)->where('event.id', $notification)
            ->whereIn('event.type', ['human_handoff', 'manual_handoff'])->exists(), 404);

        DB::table('cv_notification_acknowledgements')->insertOrIgnore([
            'notification_id' => $notification, 'user_id' => $request->user()->id, 'acknowledged_at' => now(),
        ]);

        return response()->json(['acknowledged' => true]);
    }

    private function stream(Request $request): Builder
    {
        return DB::table('cv_notification_events as event')
            ->join('cv_conversations as thread', 'thread.id', '=', 'event.conversation_id')
            ->join('cv_contact_identities as identity', 'identity.id', '=', 'thread.contact_identity_id')
            ->join('cv_channel_accounts as account', 'account.id', '=', 'identity.channel_account_id')
            ->where('account.is_demo', (bool) $request->user()->is_demo);
    }
}
