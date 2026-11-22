<?php

namespace App\Http\Controllers;

use App\Actions\RecordWhatsAppDeliveryStatus;
use App\Enums\WorkspacePermission;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    private const array CHANNELS = ['whatsapp', 'messenger'];

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $canViewUsers = $user?->can(WorkspacePermission::ViewUsers->value) ?? false;
        $now = CarbonImmutable::now('Asia/Dhaka');
        $today = $now->startOfDay();
        $demo = (bool) $user->is_demo;

        return Inertia::render('Dashboard', [
            'name' => $user?->name,
            'accountCount' => $canViewUsers
                ? User::query()->where('is_demo', $user->is_demo)->count()
                : null,
            'converse' => $user->can(WorkspacePermission::ViewConverseInbox->value)
                ? $this->converse($demo, $today, $now) : null,
            'aiUsage' => ! $demo && $user->can(WorkspacePermission::ManageSettings->value)
                ? $this->aiUsage($today->subDays(6), $now) : null,
            'knowledge' => $user->can(WorkspacePermission::ManageConverseKnowledge->value)
                ? $this->knowledge($demo) : null,
        ]);
    }

    private function converse(bool $demo, CarbonImmutable $today, CarbonImmutable $now): array
    {
        $threads = DB::table('cv_conversations as threads')
            ->join('cv_contact_identities as identities', 'identities.id', '=', 'threads.contact_identity_id')
            ->join('cv_channel_accounts as accounts', 'accounts.id', '=', 'identities.channel_account_id')
            ->where('accounts.is_demo', $demo);
        $waiting = (clone $threads)->join('cv_contacts as contacts', 'contacts.id', '=', 'identities.contact_id')
            ->where('threads.mode', 'human')->whereIn('threads.status', ['open', 'pending'])
            ->whereNull('contacts.opted_out_at')->where('accounts.enabled', true)
            ->where(fn (Builder $last) => $last->from('cv_messages as messages')->select('messages.direction')
                ->whereColumn('messages.conversation_id', 'threads.id')
                ->where(fn (Builder $visible) => $visible->where('messages.direction', 'in')
                    ->orWhereIn('messages.delivery_status', RecordWhatsAppDeliveryStatus::ACCEPTED))
                ->orderByDesc('messages.occurred_at')->orderByDesc('messages.id')->limit(1), '=', 'in');

        $demoInquiries = (clone $threads)->where('threads.status', 'open')
            ->whereExists(fn (Builder $tags) => $tags->selectRaw('1')->from('cv_conversation_tag as pivot')
                ->join('cv_conversation_tags as tag', 'tag.id', '=', 'pivot.tag_id')
                ->whereColumn('pivot.conversation_id', 'threads.id')->where('tag.name', 'Demo'))->count();

        $waitingList = (clone $waiting)->orderByDesc('threads.last_message_at')->orderByDesc('threads.id')->limit(5)
            ->get(['threads.id', 'contacts.name', 'accounts.type as channel', 'threads.last_message_at'])
            ->map(fn (object $thread): array => [
                ...((array) $thread),
                'last_message_at' => CarbonImmutable::parse($thread->last_message_at, 'UTC')->toIso8601String(),
            ]);

        $activity = (clone $threads)->join('cv_messages as messages', 'messages.conversation_id', '=', 'threads.id')
            ->whereIn('accounts.type', self::CHANNELS)
            ->whereBetween('messages.occurred_at', [$today->subDays(6)->utc(), $now->utc()])
            ->where(fn (Builder $messages) => $messages->where('messages.direction', 'in')
                ->orWhere(fn (Builder $ai) => $ai->where('messages.direction', 'out')->where('messages.sender_type', 'ai')
                    ->whereIn('messages.delivery_status', RecordWhatsAppDeliveryStatus::ACCEPTED)))
            ->select('accounts.type')
            ->selectRaw("COUNT(CASE WHEN messages.direction = 'out' AND messages.occurred_at >= ? THEN 1 END) as ai_today", [$today->utc()])
            ->groupBy('accounts.type');
        $days = [];
        for ($index = 0; $index < 7; $index++) {
            $day = $today->subDays(6 - $index);
            $days[$index] = $day->toDateString();
            $activity->selectRaw("COUNT(CASE WHEN messages.direction = 'in' AND messages.occurred_at >= ? AND messages.occurred_at < ? THEN 1 END) as day_{$index}",
                [$day->utc(), $day->addDay()->utc()]);
        }
        $activity = $activity->get()->keyBy('type');

        return [
            'new_today' => (clone $threads)->whereBetween('threads.created_at', [$today->utc(), $now->utc()])->count(),
            'waiting_count' => $waiting->count(),
            'waiting' => $waitingList,
            'open_demo_inquiries' => $demoInquiries,
            'ai_replies_today' => (int) $activity->sum('ai_today'),
            'activity' => array_map(fn (string $date, int $index): array => [
                'date' => $date,
                'whatsapp' => (int) ($activity->get('whatsapp')->{"day_{$index}"} ?? 0),
                'messenger' => (int) ($activity->get('messenger')->{"day_{$index}"} ?? 0),
            ], $days, array_keys($days)),
        ];
    }

    private function aiUsage(CarbonImmutable $from, CarbonImmutable $now): object
    {
        return DB::table('cv_ai_calls')->whereBetween('called_at', [$from->utc(), $now->utc()])->selectRaw("
            COUNT(*) as calls,
            COUNT(CASE WHEN billing IN ('local', 'free') THEN 1 END) as free_calls,
            COUNT(CASE WHEN billing = 'paid' THEN 1 END) as paid_calls,
            COUNT(CASE WHEN status IN ('rejected', 'unavailable') THEN 1 END) as unsuccessful_calls,
            SUM(total_tokens) as tokens,
            SUM(reported_cost_usd) as reported_cost
        ")->first();
    }

    private function knowledge(bool $demo): array
    {
        $collections = DB::table('cv_knowledge_collections as collections')->where('collections.is_demo', $demo)
            ->where('collections.enabled', true);
        $documents = (clone $collections)->join('cv_knowledge_documents as documents', 'documents.collection_id', '=', 'collections.id')->selectRaw("
            COUNT(CASE WHEN documents.status = 'indexed' THEN 1 END) as indexed,
            COUNT(CASE WHEN documents.status = 'queued' THEN 1 END) as queued,
            COUNT(CASE WHEN documents.status = 'failed' THEN 1 END) as failed
        ")->first();

        return ['collections' => $collections->count(), ...(array) $documents];
    }
}
