<?php

namespace App\Http\Controllers;

use App\Actions\ConverseReviewQueue;
use App\Actions\RecordWhatsAppDeliveryStatus;
use App\Actions\SendConverseReply;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ConverseReviewQueueController extends Controller
{
    public function index(Request $request, ConverseReviewQueue $queue, SendConverseReply $send): Response
    {
        $scope = $this->scope($request);
        $closed = $request->query('status') === 'closed';
        $tasks = clone $scope;
        if ($closed) {
            $tasks->where(fn (Builder $query) => $query->whereNotNull('task.closed_at')
                ->orWhereIn('reply.delivery_status', RecordWhatsAppDeliveryStatus::ACCEPTED)
                ->orWhereIn('ai_reply.delivery_status', RecordWhatsAppDeliveryStatus::ACCEPTED))
                ->orderByDesc('task.id');
        } else {
            $queue->pending($tasks)->orderByRaw('task.notification_id IS NULL')->orderBy('task.id');
        }

        $id = $request->integer('task');
        $selected = $id > 0 ? (clone $scope)->where('task.id', $id)->first([
            'task.id', 'task.conversation_id', 'task.inbound_message_id', 'task.reason',
            'task.notification_id', 'task.closed_at', 'contact.name', 'account.type as channel',
            'inbound.body as question', 'inbound.occurred_at', 'reply.delivery_status', 'reply.body as attempted_reply',
            'ai_reply.delivery_status as ai_delivery_status',
        ]) : null;
        abort_if($id > 0 && $selected === null, 404);

        return Inertia::render('Converse/ReviewQueue', [
            'status' => $closed ? 'closed' : 'pending',
            'pendingCount' => $closed ? $queue->pending(clone $scope)->count() : null,
            'tasks' => $tasks->paginate(20, ['task.id', 'task.reason', 'task.notification_id', 'task.closed_at',
                'contact.name', 'account.type as channel', 'inbound.body as question',
                'inbound.occurred_at', 'reply.delivery_status', 'ai_reply.delivery_status as ai_delivery_status'])
                ->appends(['status' => $closed ? 'closed' : 'pending'])
                ->through(function (object $task): array {
                    $task->question = mb_strimwidth($task->question, 0, 160, '…');

                    return $this->present($task);
                }),
            'selected' => $selected === null ? null : $this->details($request, $selected, $send),
        ]);
    }

    public function reply(Request $request, int $task, SendConverseReply $send): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:2000'], 'operation_id' => ['required', 'uuid']]);
        $target = $this->scope($request)->where('task.id', $task)->first(['task.conversation_id']);
        abort_if($target === null, 404);

        $status = $send($request->user(), (int) $target->conversation_id, $data['operation_id'], $data['body'], reviewTask: $task);
        if (! in_array($status, RecordWhatsAppDeliveryStatus::ACCEPTED, true)) {
            throw ValidationException::withMessages(['body' => __('workspace.'.match ($status) {
                'sending' => 'reply_sending',
                'unknown' => 'reply_unknown',
                default => 'reply_failed',
            })]);
        }

        Inertia::flash('success', __('workspace.ai_review.reply_sent'));

        return to_route('converse.review.index');
    }

    public function close(Request $request, int $task): RedirectResponse
    {
        $target = $this->scope($request)->where('task.id', $task)->first(['task.conversation_id']);
        abort_if($target === null, 404);
        DB::transaction(function () use ($target, $task, $request): void {
            DB::table('cv_conversations')->where('id', $target->conversation_id)->lockForUpdate()->first();
            $current = DB::table('cv_ai_review_tasks')->where('id', $task)->lockForUpdate()->firstOrFail();
            $reply = $current->reply_message_id === null ? null : DB::table('cv_messages')
                ->find($current->reply_message_id, ['delivery_status', 'occurred_at']);
            $aiDelivery = $current->ai_message_id === null ? null : DB::table('cv_messages')
                ->where('id', $current->ai_message_id)->value('delivery_status');
            if ($reply?->delivery_status === 'sending' && Carbon::parse($reply->occurred_at)->gt(now()->subMinutes(2))) {
                throw ValidationException::withMessages(['body' => __('workspace.ai_review.reply_locked')]);
            }
            if ($current->closed_at === null && ! in_array($reply?->delivery_status, RecordWhatsAppDeliveryStatus::ACCEPTED, true)
                && ! in_array($aiDelivery, RecordWhatsAppDeliveryStatus::ACCEPTED, true)) {
                DB::table('cv_ai_review_tasks')->where('id', $task)
                    ->update(['closed_at' => now(), 'closed_by' => $request->user()->id]);
            }
        }, attempts: 3);
        Inertia::flash('success', __('workspace.ai_review.task_closed'));

        return to_route('converse.review.index');
    }

    private function details(Request $request, object $task, SendConverseReply $send): array
    {
        $result = $this->present($task);
        $result['blocked_reason'] = $result['state'] === 'pending'
            ? $send->reviewUnavailableReason($request->user(), (int) $task->conversation_id) : null;
        $result['history'] = DB::table('cv_messages')->where('conversation_id', $task->conversation_id)
            ->where('id', '!=', $task->inbound_message_id)
            ->where(fn (Builder $visible) => $visible->where('direction', 'in')
                ->orWhereIn('delivery_status', RecordWhatsAppDeliveryStatus::ACCEPTED))
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(6)->get(['direction', 'sender_type', 'body', 'occurred_at'])
            ->reverse()->values()->map(fn (object $message): array => [
                'direction' => $message->direction,
                'sender_type' => $message->sender_type,
                'body' => mb_strimwidth($message->body, 0, 700, '…'),
                'occurred_at' => Carbon::parse($message->occurred_at)->toISOString(),
            ]);

        return $result;
    }

    private function present(object $task): array
    {
        return [
            ...((array) $task),
            'occurred_at' => Carbon::parse($task->occurred_at)->toISOString(),
            'state' => match (true) {
                $task->closed_at !== null => 'dismissed',
                in_array($task->delivery_status, RecordWhatsAppDeliveryStatus::ACCEPTED, true) => 'answered',
                in_array($task->ai_delivery_status, RecordWhatsAppDeliveryStatus::ACCEPTED, true) => 'answered',
                default => 'pending',
            },
        ];
    }

    private function scope(Request $request): Builder
    {
        return DB::table('cv_ai_review_tasks as task')
            ->join('cv_messages as inbound', 'inbound.id', '=', 'task.inbound_message_id')
            ->join('cv_conversations as thread', 'thread.id', '=', 'task.conversation_id')
            ->join('cv_contact_identities as identity', 'identity.id', '=', 'thread.contact_identity_id')
            ->join('cv_contacts as contact', 'contact.id', '=', 'identity.contact_id')
            ->join('cv_channel_accounts as account', 'account.id', '=', 'identity.channel_account_id')
            ->leftJoin('cv_messages as reply', 'reply.id', '=', 'task.reply_message_id')
            ->leftJoin('cv_messages as ai_reply', 'ai_reply.id', '=', 'task.ai_message_id')
            ->where('account.is_demo', (bool) $request->user()->is_demo);
    }
}
