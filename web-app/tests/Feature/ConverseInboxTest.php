<?php

use App\Actions\RecordConverseInboundMessage;
use App\Enums\WorkspaceRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
    $this->withoutVite()->seed(DatabaseSeeder::class);
});

function converseChannel(string $type, bool $demo = false): int
{
    return (int) DB::table('cv_channel_accounts')->insertGetId([
        'type' => $type,
        'name' => $demo ? 'Demo account' : 'Business account',
        'external_id' => $type.'-account',
        'is_demo' => $demo,
    ]);
}

it('stores a contact, identity, thread and message once and accepts out-of-order events', function () {
    $account = converseChannel('messenger');
    $record = app(RecordConverseInboundMessage::class);
    $latest = now()->startOfSecond();

    $first = $record($account, 'sender-1', 'message-1', 'price koto?', $latest, 'A customer');
    expect($record($account, 'sender-1', 'message-1', 'price koto?', $latest))->toBe($first);
    $record($account, 'sender-1', 'message-0', 'আগের বার্তা', $latest->copy()->subMinute());

    $this->assertDatabaseCount('cv_contacts', 1);
    $this->assertDatabaseCount('cv_contact_identities', 1);
    $this->assertDatabaseCount('cv_conversations', 1);
    $this->assertDatabaseCount('cv_messages', 2);
    $this->assertDatabaseHas('cv_conversations', ['last_message_at' => $latest->toDateTimeString()]);
});

it('filters and reads only conversations in the signed-in workspace', function () {
    $staff = User::factory()->create()->assignRole(WorkspaceRole::Staff->value);
    $record = app(RecordConverseInboundMessage::class);
    $record(converseChannel('messenger'), 'sender', 'message', 'price koto?', now(), 'Public lead');
    $record(converseChannel('whatsapp'), 'sender', 'message', 'মূল্য কত?', now(), 'WhatsApp lead');
    $privateMessage = $record(converseChannel('messenger', demo: true), 'sender', 'message', 'demo text', now(), 'Demo lead');
    $privateThread = DB::table('cv_messages')->where('id', $privateMessage)->value('conversation_id');

    $this->actingAs($staff)->get(route('converse.inbox', ['channel' => 'messenger', 'search' => 'koto']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Converse/Inbox')
            ->has('conversations.data', 1)
            ->where('conversations.data.0.name', 'Public lead')
            ->where('canViewConverseInbox', true));
    $this->get(route('converse.inbox', $privateThread))->assertNotFound();

    $thread = DB::table('cv_conversations')->min('id');
    $this->get(route('converse.inbox', $thread))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('messages.data.0.body', 'price koto?'));
});

it('enforces the inbox permission on the server', function () {
    $this->get(route('converse.inbox'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('converse.inbox'))->assertForbidden();
});

it('registers and disables channel accounts with scoped permissions and an audit trail', function () {
    $staff = User::factory()->create()->assignRole(WorkspaceRole::Staff->value);
    $admin = User::factory()->create()->assignRole(WorkspaceRole::Administrator->value);
    $data = ['name' => 'Business WhatsApp', 'type' => 'whatsapp', 'external_id' => '1001'];

    $this->actingAs($staff)->post(route('converse.channels.store'), $data)->assertForbidden();
    $this->actingAs($admin)->post(route('converse.channels.store'), $data)->assertRedirect(route('converse.channels.index'));
    $this->post(route('converse.channels.store'), $data)->assertSessionHasErrors('external_id');
    $channel = DB::table('cv_channel_accounts')->where('external_id', '1001')->first();
    $this->patch(route('converse.channels.update', $channel->id), ['name' => 'Updated', 'enabled' => false])
        ->assertRedirect(route('converse.channels.index'));
    expect(app(RecordConverseInboundMessage::class)((int) $channel->id, 'sender', 'message', 'Hello', now()))->toBeNull();
    $this->assertDatabaseCount('cv_messages', 0);
    $this->assertDatabaseHas('cv_audit_events', ['user_id' => $admin->id, 'subject_id' => $channel->id, 'action' => 'channel.updated']);
    $this->assertDatabaseCount('cv_audit_events', 2);
});

it('changes handling mode and status only with inbox management permission and audits the result', function () {
    $viewer = User::factory()->create()->givePermissionTo('converse.inbox.view');
    $staff = User::factory()->create()->assignRole(WorkspaceRole::Staff->value);
    $record = app(RecordConverseInboundMessage::class);
    $message = $record(converseChannel('messenger'), 'sender', 'message', 'Hello', now());
    $thread = DB::table('cv_messages')->where('id', $message)->value('conversation_id');
    $url = route('converse.inbox.update', $thread);

    $this->actingAs($viewer)->patch($url, ['mode' => 'human', 'status' => 'pending'])->assertForbidden();
    $this->actingAs($staff)->patch($url, ['mode' => 'human', 'status' => 'pending'])
        ->assertRedirect(route('converse.inbox', $thread));
    $this->assertDatabaseHas('cv_conversations', ['id' => $thread, 'mode' => 'human', 'status' => 'pending']);
    $this->assertDatabaseHas('cv_audit_events', ['action' => 'conversation.updated', 'subject_id' => $thread, 'user_id' => $staff->id]);
    $this->get(route('converse.inbox', ['status' => 'resolved']))
        ->assertInertia(fn (Assert $page) => $page->has('conversations.data', 0));
});

it('shows the selected Messenger sender profile and reuses it while polling', function () {
    Http::preventStrayRequests();
    $account = converseChannel('messenger');
    $token = Crypt::encryptString('test-page-token');
    DB::table('cv_channel_accounts')->where('id', $account)->update(['send_token' => $token]);
    $photo = 'https://platform-lookaside.fbsbx.com/profile-photo';
    $url = 'https://graph.facebook.com/'.config('converse.graph_version').'/123456789';
    Http::fake([$url.'?*' => Http::response([
        'first_name' => 'Sample',
        'last_name' => 'Customer',
        'profile_pic' => $photo,
    ])]);

    $message = app(RecordConverseInboundMessage::class)($account, '123456789', 'profile-message', 'Hello', now());
    $thread = DB::table('cv_messages')->where('id', $message)->value('conversation_id');
    $this->actingAs(User::factory()->create()->assignRole(WorkspaceRole::Staff->value))
        ->get(route('converse.inbox'))->assertOk();
    Http::assertNothingSent();

    for ($visit = 0; $visit < 2; $visit++) {
        $this->get(route('converse.inbox', $thread))->assertInertia(fn (Assert $page) => $page
            ->where('thread.name', 'Sample Customer')
            ->where('thread.sender_photo', $photo)
            ->missing('thread.send_token')
            ->missing('thread.sender_id'));
    }

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->hasHeader('Authorization', 'Bearer test-page-token')
        && str_starts_with($request->url(), $url.'?')
        && $request['fields'] === 'first_name,last_name,profile_pic'
        && ! str_contains($request->url(), 'test-page-token'));
});

it('keeps the inbox usable when Meta denies profile access or returns an unsupported photo URL', function (?array $response, int $status, string $name) {
    Http::preventStrayRequests();
    Http::fake(['https://graph.facebook.com/*' => Http::response($response, $status)]);
    $account = converseChannel('messenger');
    DB::table('cv_channel_accounts')->where('id', $account)->update(['send_token' => Crypt::encryptString('test-page-token')]);
    $message = app(RecordConverseInboundMessage::class)($account, '123456789', 'profile-message', 'Hello', now(), 'Stored name');
    $thread = DB::table('cv_messages')->where('id', $message)->value('conversation_id');

    $this->actingAs(User::factory()->create()->assignRole(WorkspaceRole::Staff->value))
        ->get(route('converse.inbox', $thread))->assertInertia(fn (Assert $page) => $page
        ->where('thread.name', $name)
        ->where('thread.sender_photo', null)
        ->where('messages.data.0.body', 'Hello'));
})->with([
    'missing permission' => [null, 403, 'Stored name'],
    'unsupported photo host' => [['first_name' => 'Sample', 'profile_pic' => 'https://fbcdn.net.example.invalid/avatar'], 200, 'Sample'],
]);
