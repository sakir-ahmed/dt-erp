<?php

use App\Actions\RecordConverseInboundMessage;
use App\Actions\SendConverseReply;
use App\Jobs\ReplyToConverseTurn;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
    $this->seed(DatabaseSeeder::class);
    Http::preventStrayRequests();
});

function noticeAccount(bool $demo = false): int
{
    return DB::table('cv_channel_accounts')->insertGetId([
        'name' => 'Notice fixture', 'type' => 'messenger', 'external_id' => '100',
        'is_demo' => $demo, 'send_token' => Crypt::encryptString('fixture-token'),
    ]);
}

it('starts at the current cursor and reports only new, deduplicated, workspace-scoped events', function () {
    $account = noticeAccount();
    $demo = noticeAccount(true);
    $record = app(RecordConverseInboundMessage::class);
    $record($account, 'sender', 'old', 'Old inquiry', now());
    $url = route('converse.inbox.notifications');

    $this->actingAs(User::factory()->create())->getJson($url)->assertForbidden();
    $this->actingAs(User::factory()->create()->assignRole('staff'));
    $cursor = $this->getJson($url)->assertOk()->assertJsonCount(0, 'events')->json('cursor');
    $record($demo, 'sender', 'demo', 'Demo inquiry', now());
    $message = $record($account, 'sender', 'new', 'New inquiry', now());
    $record($account, 'sender', 'new', 'New inquiry replay', now());
    $response = $this->getJson($url.'?after='.$cursor)->assertOk()->assertJsonCount(1, 'events')
        ->assertJsonPath('events.0.type', 'ai_inbound')->assertJsonMissingPath('events.0.body');

    $conversation = DB::table('cv_messages')->where('id', $message)->value('conversation_id');
    $update = route('converse.inbox.update', $conversation);
    $this->patch($update, ['mode' => 'human', 'status' => 'pending'])->assertRedirect();
    $this->patch($update, ['mode' => 'human', 'status' => 'pending'])->assertRedirect();
    $record($account, 'sender', 'human', 'Follow-up for staff', now());
    $handoff = $this->getJson($url.'?after='.$response->json('cursor'))->assertOk()->assertJsonCount(1, 'events')
        ->assertJsonPath('events.0.type', 'manual_handoff')
        ->assertJsonPath('handoffs.0.conversation_id', $conversation);
    $this->getJson($url.'?after='.$handoff->json('cursor'))->assertOk()->assertJsonCount(0, 'events');
});

it('records AI delivery only on a provider acknowledgement and a separate actual handoff', function (bool $accepted) {
    $account = noticeAccount();
    config(['converse.ai.auto_reply' => true, 'converse.outbound_enabled' => true,
        'converse.ai.channels' => [$account => ['enabled' => true]]]);
    Http::fake(['https://graph.facebook.com/*' => $accepted ? Http::response(['message_id' => 'accepted']) : Http::response([], 403)]);
    $message = app(RecordConverseInboundMessage::class)($account, 'sender', 'inbound', 'Question', now());
    $thread = DB::table('cv_messages')->where('id', $message)->value('conversation_id');
    app(SendConverseReply::class)->automatic($thread, $message, (string) Str::uuid(), 'A grounded reply.', handoff: false);

    expect(DB::table('cv_notification_events')->orderBy('id')->pluck('type')->all())->toBe(
        $accepted ? ['ai_inbound', 'ai_reply'] : ['ai_inbound', 'human_handoff'],
    );
})->with([true, false]);

it('creates one durable automatic handoff for an unanswered question and acknowledges it per staff member', function () {
    $account = noticeAccount();
    config(['converse.ai.auto_reply' => true, 'converse.outbound_enabled' => true,
        'converse.ai.channels' => [$account => ['enabled' => true, 'collections' => []]]]);
    Http::fake(['https://graph.facebook.com/*' => Http::response(['message_id' => 'handoff-fixture'])]);

    $user = User::factory()->create()->assignRole('staff');
    $this->actingAs($user);
    $url = route('converse.inbox.notifications');
    $message = app(RecordConverseInboundMessage::class)($account, 'sender', 'unknown', 'What warranty covers fictional hardware?', now());
    $baseline = $this->getJson($url)->assertJsonCount(0, 'events')->json('cursor');
    $job = new ReplyToConverseTurn($message);
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);

    $firstPoll = $this->getJson($url.'?after='.$baseline)->assertJsonCount(1, 'events')
        ->assertJsonPath('events.0.type', 'human_handoff')->assertJsonCount(1, 'handoffs');
    $notification = $firstPoll->json('events.0.id');
    $this->getJson($url.'?after='.$notification)->assertJsonCount(0, 'events')->assertJsonCount(1, 'handoffs');
    $this->getJson($url)->assertJsonCount(0, 'events')->assertJsonCount(1, 'handoffs');

    $ack = route('converse.inbox.notifications.acknowledge', $notification);
    $demo = User::factory()->create(['is_demo' => true])->assignRole('staff');
    $this->actingAs($demo)->postJson($ack)->assertNotFound();
    $this->actingAs($user)->postJson($ack)->assertOk();
    $this->postJson($ack)->assertOk();
    $this->getJson($url)->assertJsonCount(0, 'handoffs');
    $this->actingAs(User::factory()->create()->assignRole('staff'))->getJson($url)->assertJsonCount(1, 'handoffs');
    $this->assertDatabaseCount('cv_notification_acknowledgements', 1);
    Http::assertSentCount(1);
});
