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
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite()->seed(DatabaseSeeder::class);
    config(['converse.outbound_enabled' => true, 'inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
});

function replyThread(string $type): array
{
    $account = DB::table('cv_channel_accounts')->insertGetId([
        'type' => $type,
        'name' => 'Fixture channel',
        'external_id' => '1234',
        'send_token' => Crypt::encryptString('local-fixture-token'),
    ]);
    app(RecordConverseInboundMessage::class)($account, '8801000000000', 'first', 'Hi', now()->subMinute());
    $conversation = (int) DB::table('cv_conversations')->soleValue('id');
    DB::table('cv_conversations')->where('id', $conversation)->update(['mode' => 'human']);

    return [$account, $conversation];
}

it('sends human text through the correct provider and does not replay an operation', function (string $type, array $response) {
    Http::fake(['graph.facebook.com/*' => Http::response($response)]);
    [$account, $conversation] = replyThread($type);
    $user = User::factory()->create()->assignRole(WorkspaceRole::Staff->value);
    $data = ['body' => 'দাম জানাতে পারি।', 'operation_id' => (string) Str::uuid()];

    $this->actingAs($user)->post(route('converse.inbox.reply', $conversation), $data)->assertRedirect();
    $this->post(route('converse.inbox.reply', $conversation), $data)->assertRedirect();
    $this->post(route('converse.inbox.reply', $conversation), [...$data, 'body' => 'Conflicting replay'])->assertSessionHasErrors('body');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/1234/messages')
        && $request->hasHeader('Authorization', 'Bearer local-fixture-token')
        && ($type === 'whatsapp'
            ? $request['to'] === '+8801000000000' && $request['recipient_type'] === 'individual' && $request['biz_opaque_callback_data'] === $data['operation_id'] && $request['text']['body'] === $data['body']
            : $request['recipient']['id'] === '8801000000000' && $request['messaging_type'] === 'RESPONSE' && $request['message']['text'] === $data['body']));
    $this->assertDatabaseHas('cv_messages', ['direction' => 'out', 'delivery_status' => 'accepted', 'external_id' => 'provider-id', 'sent_by' => $user->id]);
    $this->get(route('converse.inbox', $conversation))->assertInertia(fn (Assert $page) => $page
        ->where('messages.data.0.delivery_status', 'accepted')->where('replyBlockedReason', null));
})->with([
    'Messenger' => ['messenger', ['message_id' => 'provider-id']],
    'WhatsApp' => ['whatsapp', ['messages' => [['id' => 'provider-id']]]],
]);

it('blocks sends outside the window, in AI mode, after opt-out and when the kill switch is off', function () {
    Http::fake();
    [$account, $conversation] = replyThread('whatsapp');
    $staff = User::factory()->create()->assignRole(WorkspaceRole::Staff->value);
    $viewer = User::factory()->create()->givePermissionTo('converse.inbox.view');
    $url = route('converse.inbox.reply', $conversation);
    $data = ['body' => 'Reply', 'operation_id' => (string) Str::uuid()];

    $this->actingAs($viewer)->post($url, $data)->assertForbidden();
    $this->actingAs($staff);
    config(['converse.outbound_enabled' => false]);
    $this->post($url, $data)->assertSessionHasErrors(['body' => __('workspace.reply_channel_unavailable')]);
    config(['converse.outbound_enabled' => true, 'inertia.ssr.enabled' => false]);

    DB::table('cv_conversations')->where('id', $conversation)->update(['mode' => 'ai']);
    $this->post($url, $data)->assertSessionHasErrors(['body' => __('workspace.reply_requires_human')]);
    DB::table('cv_conversations')->where('id', $conversation)->update(['mode' => 'human']);

    DB::table('cv_messages')->update(['occurred_at' => now()->subHours(24)]);
    $this->post($url, $data)->assertSessionHasErrors(['body' => __('workspace.reply_whatsapp_window_closed')]);

    app(RecordConverseInboundMessage::class)($account, '8801000000000', 'stop', 'বন্ধ করুন!', now());
    $this->post($url, $data)->assertSessionHasErrors(['body' => __('workspace.reply_opted_out')]);

    Http::assertNothingSent();
    $this->assertDatabaseMissing('cv_messages', ['direction' => 'out']);
});

it('keeps an ambiguous provider outcome and never retries it automatically', function () {
    Http::fake(['graph.facebook.com/*' => Http::failedConnection()]);
    [, $conversation] = replyThread('messenger');
    $data = ['body' => 'Reply', 'operation_id' => (string) Str::uuid()];

    $this->actingAs(User::factory()->create()->assignRole(WorkspaceRole::Staff->value));
    $this->post(route('converse.inbox.reply', $conversation), $data)->assertRedirect();
    $this->post(route('converse.inbox.reply', $conversation), $data)->assertRedirect();
    $this->assertDatabaseHas('cv_messages', ['operation_id' => $data['operation_id'], 'delivery_status' => 'unknown']);
    expect(DB::table('cv_messages')->where('direction', 'out')->count())->toBe(1);
});

it('stores channel credentials encrypted and omits them from the page and audit trail', function () {
    [$account] = replyThread('messenger');
    $admin = User::factory()->create()->assignRole(WorkspaceRole::Administrator->value);
    $secret = 'replacement-fixture-token';
    $url = route('converse.channels.update', $account);

    DB::table('cv_channel_accounts')->where('id', $account)->update(['send_token' => null]);
    $this->actingAs($admin)->get(route('converse.channels.index'))
        ->assertInertia(fn (Assert $page) => $page->where('channels.data.0.has_send_token', false));

    $this->patch($url, ['name' => 'Fixture', 'enabled' => true, 'send_token' => $secret])->assertRedirect();
    $this->get(route('converse.channels.index'))->assertDontSee($secret)
        ->assertInertia(fn (Assert $page) => $page
            ->where('channels.data.0.has_send_token', true)->missing('channels.data.0.send_token'));
    $ciphertext = DB::table('cv_channel_accounts')->where('id', $account)->value('send_token');
    expect($ciphertext)->not->toBe($secret);
    expect(Crypt::decryptString($ciphertext))->toBe($secret);
    expect(DB::table('cv_audit_events')->soleValue('changes'))->not->toContain($secret);

    $this->patch($url, ['name' => 'Fixture', 'enabled' => true, 'send_token' => ''])->assertRedirect();
    expect(DB::table('cv_channel_accounts')->where('id', $account)->value('send_token'))->toBe($ciphertext);

    $this->patch($url, ['name' => 'Fixture', 'enabled' => true, 'remove_send_token' => true])->assertRedirect();
    $this->assertDatabaseHas('cv_channel_accounts', ['id' => $account, 'send_token' => null]);
    $this->get(route('converse.channels.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('channels.data.0.has_send_token', false)->missing('channels.data.0.send_token'));
});
