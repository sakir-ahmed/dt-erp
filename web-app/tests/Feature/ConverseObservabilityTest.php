<?php

use App\Actions\RecordConverseInboundMessage;
use App\Jobs\ProcessConverseWebhook;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite()->seed(DatabaseSeeder::class);
    config(['converse.outbound_enabled' => true, 'converse.webhooks.meta.secret' => 'fixture-meta-secret']);
    Storage::fake('local');
    Http::preventStrayRequests();
});

it('logs the provider rejection with its error body when a send fails', function () {
    [$account, $thread] = observableThread();
    DB::table('cv_conversations')->update(['mode' => 'human']);
    Http::fake(['https://graph.facebook.com/*/101/messages' => Http::response([
        'error' => ['message' => 'Error validating access token: session has expired', 'code' => 190],
    ], 403)]);

    Log::shouldReceive('channel')->with('converse')->andReturnSelf();
    Log::shouldReceive('warning')->once()->withArgs(fn (string $message, array $context) => $message === 'send rejected by provider'
        && ($context['error'] ?? null) === 'Error validating access token: session has expired'
        && ($context['status'] ?? null) === 403);

    $this->actingAs(User::factory()->create()->assignRole('staff'))
        ->post(route('converse.inbox.reply', $thread), [
            'operation_id' => (string) Str::uuid(), 'body' => 'hello again',
        ])->assertRedirect();

    $this->assertDatabaseHas('cv_messages', ['delivery_status' => 'failed']);
});

it('exposes inbound attachment metadata to the Inbox payload', function () {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRuoAAAAASUVORK5CYII=');
    Http::fake([
        'https://cdn.fbsbx.com/attachments/*' => Http::response($png),
        'https://graph.facebook.com/*/4567*' => Http::response(['id' => '4567']),
    ]);
    DB::table('cv_channel_accounts')->insertGetId(['type' => 'messenger', 'name' => 'Fixture page', 'external_id' => '101',
        'send_token' => Crypt::encryptString('fixture-page-token')]);

    $payload = json_encode(['object' => 'page', 'entry' => [[
        'id' => '101', 'messaging' => [[
            'sender' => ['id' => '4567'], 'recipient' => ['id' => '101'], 'timestamp' => 1764232200000,
            'message' => ['mid' => 'm.observability', 'attachments' => [
                ['type' => 'image', 'payload' => ['url' => 'https://cdn.fbsbx.com/attachments/shot.png']],
            ]],
        ]],
    ]]], JSON_THROW_ON_ERROR);
    $this->call('POST', '/api/v1/converse/webhooks/meta', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, 'fixture-meta-secret'),
    ], content: $payload)->assertOk();
    app()->call([new ProcessConverseWebhook((int) DB::table('cv_webhook_events')->soleValue('id')), 'handle']);
    $thread = (int) DB::table('cv_messages')->where('external_id', 'm.observability')->value('conversation_id');

    $this->actingAs(User::factory()->create()->assignRole('staff'))
        ->get(route('converse.inbox', $thread))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Converse/Inbox')
            ->where('messages.data.0.attachment_mime', 'image/png')
            ->where('messages.data.0.attachment_name', 'attachment.png')
            ->where('messages.data.0.attachment_bytes', strlen($png)));
});

it('exposes the WhatsApp number to inbox managers only', function () {
    [$account, $thread] = observableThread();

    $this->actingAs(User::factory()->create()->assignRole('staff'))
        ->get(route('converse.inbox', $thread))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('thread.contact_phone', '8801000000201'));

    $viewer = User::factory()->create();
    $viewer->givePermissionTo('converse.inbox.view');

    $this->actingAs($viewer)
        ->get(route('converse.inbox', $thread))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('thread.contact_phone'));
});

function observableThread(string $channel = 'whatsapp'): array
{
    $account = DB::table('cv_channel_accounts')->insertGetId([
        'type' => $channel, 'name' => 'Observability fixture', 'external_id' => '101',
        'send_token' => Crypt::encryptString('fixture-channel-token'),
    ]);
    $message = app(RecordConverseInboundMessage::class)($account, '8801000000201', 'in-1', 'Hello', now()->subSecond());

    return [$account, (int) DB::table('cv_messages')->where('id', $message)->value('conversation_id')];
}
