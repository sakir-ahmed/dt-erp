<?php

use App\Jobs\ProcessConverseWebhook;
use App\Jobs\ReplyToConverseTurn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'converse.webhooks.meta.secret' => 'fixture-meta-secret',
        'converse.webhooks.meta.verify_token' => 'fixture-token',
        'converse.webhooks.whatsapp.secret' => 'fixture-whatsapp-secret',
    ]);
});

it('verifies the Meta callback challenge and rejects invalid tokens', function () {
    $url = '/api/v1/converse/webhooks/meta?hub.mode=subscribe&hub.challenge=0012&hub.verify_token=';

    $this->get($url.'fixture-token')->assertOk()->assertContent('0012');
    $this->get($url.'incorrect')->assertForbidden();
});

it('authenticates exact request bytes, queues a durable event and deduplicates Messenger text messages', function () {
    Queue::fake();
    $account = DB::table('cv_channel_accounts')->insertGetId(['type' => 'messenger', 'name' => 'Fixture page', 'external_id' => '1234']);
    config(['converse.ai.auto_reply' => true, 'converse.ai.channels' => [$account => ['enabled' => true]]]);
    $payload = json_encode(['object' => 'page', 'entry' => [[
        'id' => '1234',
        'messaging' => [[
            'sender' => ['id' => '4567'],
            'recipient' => ['id' => '1234'],
            'timestamp' => 1764232200000,
            'message' => ['mid' => 'messenger-fixture', 'text' => 'price koto?'],
        ]],
    ]]], JSON_THROW_ON_ERROR);
    $url = '/api/v1/converse/webhooks/meta';
    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, 'fixture-meta-secret')];

    $this->call('POST', $url, server: $headers, content: $payload.' ')->assertForbidden();
    $this->assertDatabaseCount('cv_webhook_events', 0);
    $this->call('POST', $url, server: $headers, content: $payload)->assertOk();
    $this->call('POST', $url, server: $headers, content: $payload)->assertOk();

    $this->assertDatabaseCount('cv_webhook_events', 1);
    $event = DB::table('cv_webhook_events')->first();
    expect($event->payload)->not->toContain('price koto?');
    expect(Crypt::decryptString($event->payload))->toBe($payload);
    Queue::assertPushedOn('converse-inbound', ProcessConverseWebhook::class);
    $this->assertDatabaseCount('cv_messages', 0);

    $job = new ProcessConverseWebhook((int) $event->id);
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);
    $this->assertDatabaseCount('cv_messages', 1);
    $this->assertDatabaseHas('cv_messages', ['body' => 'price koto?', 'external_id' => 'messenger-fixture']);
    Queue::assertPushed(ReplyToConverseTurn::class, 1);
    Queue::assertPushedOn('converse-ai', ReplyToConverseTurn::class, fn (ReplyToConverseTurn $reply): bool => $reply->inboundMessageId === (int) DB::table('cv_messages')->soleValue('id') && $reply->delay !== null);
});

it('records WhatsApp text for the configured phone number and ignores unsupported events', function () {
    config(['queue.default' => 'sync']);
    DB::table('cv_channel_accounts')->insert([
        ['type' => 'whatsapp', 'name' => 'Fixture phone', 'external_id' => '1001', 'is_demo' => false],
        ['type' => 'whatsapp', 'name' => 'Demo phone', 'external_id' => '1001', 'is_demo' => true],
    ]);

    $payload = json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
        'id' => '9001',
        'changes' => [[
            'field' => 'messages',
            'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => ['display_phone_number' => '15555550123', 'phone_number_id' => '1001'],
                'messages' => [
                    ['id' => 'wamid.fixture', 'from' => '8801000000000', 'timestamp' => '1764232200', 'type' => 'text', 'text' => ['body' => 'দাম কত?']],
                    ['id' => 'wamid.image', 'from' => '8801000000000', 'timestamp' => '1764232201', 'type' => 'image'],
                ],
            ],
        ]],
    ]]], JSON_THROW_ON_ERROR);

    $this->call('POST', '/api/v1/converse/webhooks/whatsapp', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, 'fixture-whatsapp-secret'),
    ], content: $payload)->assertOk();

    $this->assertDatabaseCount('cv_messages', 1);
    $this->assertDatabaseHas('cv_messages', ['body' => 'দাম কত?', 'external_id' => 'wamid.fixture']);
    $this->assertDatabaseHas('cv_contact_identities', [
        'channel_account_id' => DB::table('cv_channel_accounts')->where('is_demo', false)->value('id'),
        'external_id' => '8801000000000',
    ]);
});
