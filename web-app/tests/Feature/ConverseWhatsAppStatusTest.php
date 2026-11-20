<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function sendWhatsAppFixture(object $test, string $phoneId, array $fields, bool $validSignature = true): void
{
    $payload = json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
        'id' => '900', 'changes' => [[
            'field' => 'messages', 'value' => [
                'messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => $phoneId], ...$fields,
            ],
        ]],
    ]]], JSON_THROW_ON_ERROR);
    $test->call('POST', '/api/v1/converse/webhooks/whatsapp', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, $validSignature ? 'fixture-secret' : 'wrong'),
    ], content: $payload)->assertStatus($validSignature ? 200 : 403);
}

beforeEach(function () {
    config(['inertia.ssr.enabled' => false, 'queue.default' => 'sync', 'converse.outbound_enabled' => true,
        'converse.ai.auto_reply' => false, 'converse.webhooks.whatsapp.secret' => 'fixture-secret']);
    $this->withoutVite()->seed(DatabaseSeeder::class);
    Http::preventStrayRequests();
    foreach (['101', '103'] as $number) {
        DB::table('cv_channel_accounts')->insert([
            'type' => 'whatsapp', 'name' => 'Fixture '.$number, 'external_id' => $number,
            'send_token' => Crypt::encryptString('fixture-wa-token-'.$number),
        ]);
    }
});

it('preserves the contact when WhatsApp switches from phone number to business ID and updates only the right outbound delivery', function () {
    $this->freezeTime();
    $businessId = 'BD.13491208655302741918';
    $phone = '8801000001234';
    $contacts = [['wa_id' => $phone, 'user_id' => $businessId, 'profile' => ['name' => 'WhatsApp fixture customer']]];
    $message = ['id' => 'wamid.in1', 'from' => $phone, 'from_user_id' => $businessId,
        'type' => 'text', 'timestamp' => (string) now()->timestamp, 'text' => ['body' => 'Hello']];
    sendWhatsAppFixture($this, '101', ['contacts' => $contacts, 'messages' => [$message]]);
    $this->travel(2)->seconds();
    unset($message['from']);
    sendWhatsAppFixture($this, '101', ['contacts' => $contacts, 'messages' => [[
        ...$message, 'id' => 'wamid.in2', 'timestamp' => (string) now()->timestamp,
    ]]]);
    $this->assertDatabaseCount('cv_conversations', 1);
    $this->assertDatabaseHas('cv_contacts', ['name' => 'WhatsApp fixture customer']);
    $this->assertDatabaseHas('cv_contact_identities', ['external_id' => $phone, 'whatsapp_reply_address' => $businessId]);

    $thread = DB::table('cv_conversations')->soleValue('id');
    $this->actingAs(User::factory()->create()->assignRole('staff'))
        ->patch(route('converse.inbox.update', $thread), ['mode' => 'human', 'status' => 'open'])->assertRedirect();
    $operation = (string) Str::uuid();
    Http::fake(['https://graph.facebook.com/*/101/messages' => Http::response(['messages' => [['id' => 'wamid.out1']]])]);
    $this->post(route('converse.inbox.reply', $thread), ['body' => 'Reply', 'operation_id' => $operation])->assertRedirect();
    Http::assertSent(fn (Request $r) => $r['recipient'] === $businessId && ! isset($r['to'])
        && $r['biz_opaque_callback_data'] === $operation && $r->hasHeader('Authorization', 'Bearer fixture-wa-token-101'));

    $receipt = ['id' => 'wamid.out1', 'status' => 'read', 'recipient_user_id' => $businessId, 'timestamp' => (string) now()->timestamp];
    sendWhatsAppFixture($this, '101', ['statuses' => [$receipt]], validSignature: false);
    sendWhatsAppFixture($this, '103', ['statuses' => [$receipt]]);
    sendWhatsAppFixture($this, '101', ['statuses' => [[...$receipt, 'recipient_user_id' => 'BD.123']]]);
    $this->assertDatabaseHas('cv_messages', ['external_id' => 'wamid.out1', 'delivery_status' => 'accepted']);
    sendWhatsAppFixture($this, '101', ['statuses' => [$receipt]]);
    sendWhatsAppFixture($this, '101', ['statuses' => [[...$receipt, 'status' => 'delivered'], [...$receipt, 'status' => 'failed']]]);
    $this->assertDatabaseHas('cv_messages', ['external_id' => 'wamid.out1', 'delivery_status' => 'read']);
    $this->assertDatabaseCount('cv_messages', 3);
    Http::assertSentCount(1);
});

it('reconciles an uncertain send by its signed operation ID and records failed deliveries', function () {
    $phone = '8801000001234';
    sendWhatsAppFixture($this, '101', ['messages' => [['id' => 'wamid.in', 'from' => $phone,
        'type' => 'text', 'timestamp' => (string) now()->timestamp, 'text' => ['body' => 'Hello']]]]);
    $thread = DB::table('cv_conversations')->soleValue('id');
    $this->actingAs(User::factory()->create()->assignRole('staff'))
        ->patch(route('converse.inbox.update', $thread), ['mode' => 'human', 'status' => 'open'])->assertRedirect();
    Http::fake(['https://graph.facebook.com/*/101/messages' => Http::failedConnection()]);
    $operation = (string) Str::uuid();
    $this->post(route('converse.inbox.reply', $thread), ['body' => 'Reply', 'operation_id' => $operation])->assertRedirect();
    $this->assertDatabaseHas('cv_messages', ['operation_id' => $operation, 'delivery_status' => 'unknown']);
    $receipt = ['id' => 'wamid.out2', 'status' => 'sent', 'recipient_id' => $phone, 'biz_opaque_callback_data' => $operation];
    sendWhatsAppFixture($this, '101', ['statuses' => [$receipt]]);
    $this->assertDatabaseHas('cv_messages', ['operation_id' => $operation, 'external_id' => 'wamid.out2', 'delivery_status' => 'sent']);
    sendWhatsAppFixture($this, '101', ['statuses' => [[...$receipt, 'status' => 'failed']]]);
    $this->assertDatabaseHas('cv_messages', ['operation_id' => $operation, 'external_id' => 'wamid.out2', 'delivery_status' => 'failed']);
    $this->post(route('converse.inbox.reply', $thread), ['body' => 'Reply', 'operation_id' => $operation])->assertRedirect();
    $this->assertDatabaseCount('cv_messages', 2);
});
