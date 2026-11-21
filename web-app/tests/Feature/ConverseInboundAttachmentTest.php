<?php

use App\Jobs\ProcessConverseWebhook;
use App\Jobs\ReplyToConverseTurn;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite()->seed(DatabaseSeeder::class);
    config([
        'converse.webhooks.meta.secret' => 'fixture-meta-secret',
        'converse.webhooks.whatsapp.secret' => 'fixture-whatsapp-secret',
    ]);
    Storage::fake('local');
    Http::preventStrayRequests();
});

/**
 * Deliver a signed provider webhook and process its durable event.
 */
function converseInboundWebhook(string $provider, array $payload): void
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $secret = $provider === 'meta' ? 'fixture-meta-secret' : 'fixture-whatsapp-secret';
    test()->call('POST', "/api/v1/converse/webhooks/{$provider}", server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
    ], content: $body)->assertOk();

    app()->call([new ProcessConverseWebhook((int) DB::table('cv_webhook_events')->soleValue('id')), 'handle']);
}

function converseInboundPng(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRuoAAAAASUVORK5CYII=');
}

it('stores a Messenger attachment with its message and serves it through the Inbox', function () {
    Queue::fake();
    config(['converse.ai.auto_reply' => true]);
    DB::table('cv_channel_accounts')->insertGetId(['type' => 'messenger', 'name' => 'Fixture page', 'external_id' => '1234',
        'send_token' => Crypt::encryptString('fixture-page-token')]);
    Http::fake(['https://cdn.fbsbx.com/attachments/*' => Http::response(converseInboundPng())]);

    converseInboundWebhook('meta', ['object' => 'page', 'entry' => [[
        'id' => '1234',
        'messaging' => [[
            'sender' => ['id' => '4567'], 'recipient' => ['id' => '1234'], 'timestamp' => 1764232200000,
            'message' => ['mid' => 'messenger-image', 'attachments' => [
                ['type' => 'image', 'payload' => ['url' => 'https://cdn.fbsbx.com/attachments/fixture.png']],
            ]],
        ]],
    ]]]);

    $message = DB::table('cv_messages')->sole();
    expect($message->body)->toBe('')->and($message->direction)->toBe('in');
    $asset = DB::table('cv_outbound_assets')->sole();
    expect((array) $asset)->toMatchArray(['conversation_id' => $message->conversation_id, 'owner_id' => null,
        'mime' => 'image/png', 'approved' => false, 'name' => 'attachment.png']);
    expect($message->outbound_asset_id)->toBe($asset->id)
        ->and(Storage::disk('local')->exists($asset->path))->toBeTrue();

    $staff = User::factory()->create()->assignRole('staff');
    $this->actingAs($staff)
        ->get(route('converse.inbox.attachments.download', [$message->conversation_id, $message->id]))
        ->assertDownload('attachment.png');
    Queue::assertNotPushed(ReplyToConverseTurn::class);
});

it('stores a WhatsApp document with its caption, verifying the provider digest', function () {
    DB::table('cv_channel_accounts')->insertGetId(['type' => 'whatsapp', 'name' => 'Fixture phone', 'external_id' => '1001',
        'send_token' => Crypt::encryptString('fixture-phone-token')]);
    $pdf = "%PDF-1.4\nFixture quotation\n%%EOF";
    Http::fake([
        'https://graph.facebook.com/*/wamud.document' => Http::response(['url' => 'https://mmg.whatsapp.net/b/fixture',
            'mime_type' => 'application/pdf', 'sha256' => hash('sha256', $pdf)]),
        'https://mmg.whatsapp.net/*' => Http::response($pdf),
    ]);

    converseInboundWebhook('whatsapp', ['object' => 'whatsapp_business_account', 'entry' => [[
        'id' => '9001', 'changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => '1001'],
            'messages' => [[
                'id' => 'wamid.document', 'from' => '8801000000201', 'timestamp' => '1764232200', 'type' => 'document',
                'document' => ['id' => 'wamud.document', 'caption' => 'Quotation please', 'filename' => 'quotation v1.pdf',
                    'sha256' => hash('sha256', $pdf)],
            ]],
        ]]],
    ]]]);

    $message = DB::table('cv_messages')->sole();
    expect($message->body)->toBe('Quotation please');
    $asset = DB::table('cv_outbound_assets')->sole();
    expect($asset->name)->toBe('quotation-v1.pdf')->and($asset->mime)->toBe('application/pdf')
        ->and($message->outbound_asset_id)->toBe($asset->id);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'wamud.document')
        && $r->hasHeader('Authorization', 'Bearer fixture-phone-token'));
});

it('keeps the message but drops the file when the digest does not match or the download fails', function (string $mode) {
    DB::table('cv_channel_accounts')->insertGetId(['type' => 'whatsapp', 'name' => 'Fixture phone', 'external_id' => '1001',
        'send_token' => Crypt::encryptString('fixture-phone-token')]);
    $png = converseInboundPng();
    Http::fake([
        'https://graph.facebook.com/*/wamud.image' => Http::response(['url' => 'https://mmg.whatsapp.net/b/fixture']),
        'https://mmg.whatsapp.net/*' => $mode === 'unreachable' ? Http::response(status: 404) : Http::response($png),
    ]);

    converseInboundWebhook('whatsapp', ['object' => 'whatsapp_business_account', 'entry' => [[
        'id' => '9001', 'changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => '1001'],
            'messages' => [[
                'id' => 'wamid.image', 'from' => '8801000000201', 'timestamp' => '1764232200', 'type' => 'image',
                'image' => ['id' => 'wamud.image', 'sha256' => $mode === 'tampered' ? str_repeat('a', 64) : hash('sha256', $png)],
            ]],
        ]]],
    ]]]);

    expect(DB::table('cv_messages')->sole()->outbound_asset_id)->toBeNull();
    $this->assertDatabaseEmpty('cv_outbound_assets');
})->with([['tampered'], ['unreachable']]);

it('refuses Messenger attachments outside the pinned media hosts', function () {
    DB::table('cv_channel_accounts')->insertGetId(['type' => 'messenger', 'name' => 'Fixture page', 'external_id' => '1234']);

    converseInboundWebhook('meta', ['object' => 'page', 'entry' => [[
        'id' => '1234',
        'messaging' => [[
            'sender' => ['id' => '4567'], 'recipient' => ['id' => '1234'], 'timestamp' => 1764232200000,
            'message' => ['mid' => 'messenger-evil', 'text' => 'see this', 'attachments' => [
                ['type' => 'file', 'payload' => ['url' => 'https://evil.example/steal.pdf']],
            ]],
        ]],
    ]]]);

    $message = DB::table('cv_messages')->sole();
    expect($message->body)->toBe('see this')->and($message->outbound_asset_id)->toBeNull();
    $this->assertDatabaseEmpty('cv_outbound_assets');
    Http::assertNothingSent();
});
