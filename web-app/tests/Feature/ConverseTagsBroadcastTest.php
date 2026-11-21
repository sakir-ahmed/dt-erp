<?php

use App\Actions\RecordConverseInboundMessage;
use App\Jobs\SendConverseBroadcast;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite()->seed(DatabaseSeeder::class);
    config(['converse.outbound_enabled' => true]);
    Http::preventStrayRequests();
});

function taggedThread(string $type = 'whatsapp', array $tags = [], bool $fresh = true): int
{
    static $sequence = 0;
    $account = DB::table('cv_channel_accounts')->insertGetId([
        'type' => $type, 'name' => "Tag fixture {$type}", 'external_id' => (string) (601 + $sequence++),
        'send_token' => Crypt::encryptString('fixture-channel-token'),
    ]);
    $message = app(RecordConverseInboundMessage::class)($account, '8801000000401', 'in-'.Str::uuid(), 'Hello there', $fresh ? now()->subHour() : now()->subDays(2));
    $conversation = (int) DB::table('cv_messages')->where('id', $message)->value('conversation_id');
    if ($tags !== []) {
        $user = User::factory()->create();
        foreach ($tags as $tag) {
            $tagId = DB::table('cv_conversation_tags')->where('name', $tag)->value('id')
                ?? DB::table('cv_conversation_tags')->insertGetId([
                    'name' => $tag, 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            DB::table('cv_conversation_tag')->insertOrIgnore(['conversation_id' => $conversation, 'tag_id' => $tagId]);
        }
    }

    return $conversation;
}

it('syncs tags creating unknown ones and reporting counts', function () {
    $conversation = taggedThread();
    $staff = User::factory()->create()->assignRole('staff');

    $this->actingAs($staff)->put("/converse/inbox/{$conversation}/tags", ['tags' => ['new-customer', 'paid']])->assertRedirect();
    $this->actingAs($staff)->put("/converse/inbox/{$conversation}/tags", ['tags' => ['paid']])->assertRedirect();

    expect(DB::table('cv_conversation_tags')->pluck('name')->all())->toBe(['new-customer', 'paid'])
        ->and(DB::table('cv_conversation_tag')->where('conversation_id', $conversation)->count())->toBe(1);

    $this->actingAs($staff)->get(route('converse.inbox', ['conversation' => $conversation, 'tags' => ['paid']]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            // Tags without conversations drop out of the filter list.
            ->has('tags', 1)
            ->where('tags.0.name', 'paid')
            ->where('tags.0.conversations_count', 1)
            ->where('thread.tags', ['paid']));

    $this->actingAs(User::factory()->create())->put("/converse/inbox/{$conversation}/tags", ['tags' => []])->assertForbidden();
});

it('filters conversations by all selected tags', function () {
    taggedThread(tags: ['new-customer', 'paid']);
    taggedThread(tags: ['new-customer']);
    $staff = User::factory()->create()->assignRole('staff');

    $this->actingAs($staff)->get(route('converse.inbox', ['tags' => ['new-customer']]))
        ->assertOk()->assertInertia(fn (AssertableInertia $response) => $response->has('conversations.data', 2));
    $this->actingAs($staff)->get(route('converse.inbox', ['tags' => ['new-customer', 'paid']]))
        ->assertInertia(fn (AssertableInertia $response) => $response->has('conversations.data', 1));
});

it('queues broadcasts to tagged conversations within the window and channel', function () {
    Queue::fake();
    $target = taggedThread('whatsapp', ['new-customer', 'paid']);
    taggedThread('whatsapp', ['new-customer']); // missing one tag
    taggedThread('messenger', ['new-customer', 'paid']); // other channel
    taggedThread('whatsapp', ['new-customer', 'paid'], fresh: false); // outside window

    $this->actingAs(User::factory()->create()->assignRole('staff'))
        ->post('/converse/inbox/broadcast', [
            'tags' => ['new-customer', 'paid'], 'channel' => 'whatsapp', 'body' => 'Installation scheduled for tomorrow.',
        ])->assertRedirect();

    Queue::assertPushed(SendConverseBroadcast::class, 1);
    Queue::assertPushed(SendConverseBroadcast::class, fn (SendConverseBroadcast $job) => $job->conversation === $target);
    $this->assertDatabaseHas('cv_audit_events', ['action' => 'conversation.broadcast_queued']);
});

it('delivers a queued broadcast through the provider', function () {
    Http::fake(['https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'mid.b1']]])]);
    $conversation = taggedThread('whatsapp', ['paid']);
    $staff = User::factory()->create()->assignRole('staff');

    $operation = (string) Str::uuid();
    app()->call([new SendConverseBroadcast($staff->id, $conversation, $operation, 'Broadcast body'), 'handle']);

    $this->assertDatabaseHas('cv_messages', ['conversation_id' => $conversation, 'body' => 'Broadcast body',
        'sender_type' => 'human', 'delivery_status' => 'accepted', 'external_id' => 'mid.b1']);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/messages')
        && ($request['text']['body'] ?? null) === 'Broadcast body');
});

it('rejects invalid broadcast payloads', function () {
    $this->actingAs(User::factory()->create()->assignRole('staff'));

    $this->post('/converse/inbox/broadcast', ['tags' => [], 'channel' => 'all', 'body' => 'x'])->assertSessionHasErrors(['tags']);
    $this->post('/converse/inbox/broadcast', ['tags' => ['paid'], 'channel' => 'sms', 'body' => 'Hello'])->assertSessionHasErrors(['channel']);
    Http::assertNothingSent();
});
