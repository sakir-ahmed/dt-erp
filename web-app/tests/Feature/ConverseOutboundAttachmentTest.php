<?php

use App\Actions\RecordConverseInboundMessage;
use App\Actions\RecordWhatsAppDeliveryStatus;
use App\Jobs\ReplyToConverseTurn;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite()->seed(DatabaseSeeder::class);
    config(['inertia.ssr.enabled' => false, 'converse.outbound_enabled' => true, 'converse.ai.auto_reply' => true,
        'converse.ai.ollama_model' => 'fixture-model']);
    Storage::fake('local');
    Http::preventStrayRequests();
});

function converseAttachmentThread(string $channel, string $question = 'Hello'): array
{
    $account = DB::table('cv_channel_accounts')->insertGetId([
        'type' => $channel, 'name' => 'Attachment fixture', 'external_id' => '101',
        'send_token' => Crypt::encryptString('fixture-channel-token'),
    ]);
    $message = app(RecordConverseInboundMessage::class)($account, '8801000000201', 'in-1', $question, now()->subSecond());

    return [$account, DB::table('cv_messages')->where('id', $message)->value('conversation_id'), $message];
}

function converseAttachmentPdf(string $suffix = ''): UploadedFile
{
    return UploadedFile::fake()->createWithContent('restora-catalog.pdf', "%PDF-1.4\nFixture catalog {$suffix}\n%%EOF");
}

function converseAttachmentImage(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('fixture.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRuoAAAAASUVORK5CYII='));
}

it('lets staff send files with or without text, downloads through Inbox authorization, and suppresses retries', function (string $channel, bool $withText) {
    [, $thread] = converseAttachmentThread($channel);
    $whatsapp = $channel === 'whatsapp';
    DB::table('cv_conversations')->update(['mode' => 'human']);
    Http::fake([
        'https://graph.facebook.com/*/101/media' => Http::response(['id' => '1001']),
        'https://graph.facebook.com/*/101/message_attachments' => Http::response(['attachment_id' => '2001']),
        'https://graph.facebook.com/*/101/messages' => fn (Request $r) => Http::response($whatsapp
            ? ['messages' => [['id' => 'wamid.fixture']]]
            : ['message_id' => isset($r['message']['text']) ? 'meta-text' : 'meta-file']),
    ]);
    $staff = User::factory()->create()->assignRole('staff');
    $url = route('converse.inbox.reply', $thread);
    $operation = (string) Str::uuid();
    $body = $withText ? 'Here is the catalog.' : '';
    $this->actingAs($staff)->post($url, ['operation_id' => $operation, 'body' => $body, 'attachment' => converseAttachmentPdf()])->assertRedirect();
    $this->post($url, ['operation_id' => $operation, 'body' => $body, 'attachment' => converseAttachmentPdf()])->assertRedirect();
    $this->post($url, ['operation_id' => $operation, 'body' => $body, 'attachment' => converseAttachmentPdf('other')])->assertSessionHasErrors('body');

    Http::assertSentCount($whatsapp || ! $withText ? 2 : 3);
    Http::assertSent(fn (Request $r) => $r->hasFile($whatsapp ? 'file' : 'filedata') && $r->hasHeader('Authorization', 'Bearer fixture-channel-token'));
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/messages') && ($whatsapp
        ? $r['document']['id'] === '1001' && ($r['document']['caption'] ?? '') === $body && $r['document']['filename'] === 'restora-catalog.pdf'
        : ($r['message']['attachment']['payload']['attachment_id'] ?? null) === '2001'));
    $out = DB::table('cv_messages')->where('operation_id', $operation)->firstOrFail();
    expect($out->delivery_status)->toBe('accepted');
    $this->assertDatabaseCount('cv_outbound_assets', 1);
    $download = route('converse.inbox.attachments.download', [$thread, $out->id]);
    $this->get($download)->assertDownload('restora-catalog.pdf');
    $this->actingAs(User::factory()->create())->get($download)->assertForbidden();
})->with([['messenger', true], ['messenger', false], ['whatsapp', true], ['whatsapp', false]]);

it('retries only the failed file after separate text has been accepted', function (string $channel) {
    [$account, $thread] = converseAttachmentThread($channel);
    DB::table('cv_conversations')->update(['mode' => 'human']);
    $whatsapp = $channel === 'whatsapp';
    $textId = $whatsapp ? 'wamid.text' : 'meta-text';
    $fileId = $whatsapp ? 'wamid.file' : 'meta-file';
    Http::fake([
        'https://graph.facebook.com/*/101/message_attachments' => Http::response(['attachment_id' => '2001']),
        'https://graph.facebook.com/*/101/media' => Http::response(['id' => '1001']),
        'https://graph.facebook.com/*/101/messages' => Http::sequence()
            ->push($whatsapp ? ['messages' => [['id' => $textId]]] : ['message_id' => $textId])->pushStatus(400)
            ->push($whatsapp ? ['messages' => [['id' => $fileId]]] : ['message_id' => $fileId]),
    ]);
    $operation = (string) Str::uuid();
    $this->actingAs(User::factory()->create()->assignRole('staff'));
    $this->post(route('converse.inbox.reply', $thread), [
        'operation_id' => $operation, 'body' => $whatsapp ? str_repeat('x', 1025) : 'Catalog attached', 'attachment' => converseAttachmentPdf(),
    ])->assertRedirect();
    $this->assertDatabaseHas('cv_messages', ['operation_id' => $operation, 'delivery_status' => 'partial',
        'external_id' => $textId, 'attachment_status' => 'failed']);
    $message = DB::table('cv_messages')->where('operation_id', $operation)->value('id');
    if ($whatsapp) {
        app(RecordWhatsAppDeliveryStatus::class)($account, [
            'id' => $textId, 'recipient_id' => '8801000000201', 'status' => 'read',
        ]);
        $this->assertDatabaseHas('cv_messages', ['id' => $message, 'delivery_status' => 'partial']);
    }
    $retry = route('converse.inbox.attachments.retry', [$thread, $message]);
    $this->post($retry)->assertRedirect();
    $this->post($retry)->assertRedirect();
    $this->assertDatabaseHas('cv_messages', ['operation_id' => $operation, 'delivery_status' => 'accepted',
        'external_id' => $textId, 'attachment_status' => 'accepted', 'attachment_external_id' => $fileId]);
    Http::assertSentCount(5);
    expect(Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/messages')
        && ($whatsapp ? $r['type'] === 'text' : isset($r['message']['text']))))->toHaveCount(1);
})->with(['messenger', 'whatsapp']);

it('restricts the model to approved assets in its assigned collection and sends the chosen image', function (string $channel) {
    [$account, $thread, $message] = converseAttachmentThread($channel, 'Please send me the DT Restora product book PDF');
    $admin = User::factory()->create()->assignRole('administrator');
    $this->actingAs($admin);
    $collection = DB::table('cv_knowledge_collections')->insertGetId(['name' => 'Restora fixtures', 'enabled' => true]);
    config(['converse.ai.channels' => [$account => ['enabled' => true, 'agent' => 'sales', 'collections' => [$collection]]]]);
    $route = route('converse.knowledge.outbound-assets.store', $collection);
    $this->post($route, ['title' => 'DT Restora product book', 'attachment' => converseAttachmentImage()])->assertRedirect();
    $asset = DB::table('cv_outbound_assets')->soleValue('id');
    $this->post($route, ['title' => 'Private draft', 'attachment' => converseAttachmentPdf()])->assertRedirect();
    $this->patch(route('converse.knowledge.outbound-assets.update', [$collection, $asset]), ['approved' => true])->assertRedirect();

    $whatsapp = $channel === 'whatsapp';
    Http::fake([
        'http://127.0.0.1:11434/api/chat' => function (Request $r) use ($asset) {
            $input = json_decode($r['messages'][1]['content'], true);
            expect($input['approved_assets'])->toBe([['id' => $asset, 'title' => 'DT Restora product book']]);

            return Http::response(['done' => true, 'done_reason' => 'stop',
                'message' => ['content' => json_encode(['asset_id' => $asset, 'confidence' => 0.95])]]);
        },
        'https://graph.facebook.com/*/101/media' => Http::response(['id' => '1001']),
        'https://graph.facebook.com/*/101/message_attachments' => Http::response(['attachment_id' => '2001']),
        'https://graph.facebook.com/*/101/messages' => fn (Request $r) => Http::response($whatsapp
            ? ['messages' => [['id' => 'wamid.fixture']]]
            : ['message_id' => isset($r['message']['text']) ? 'meta-text' : 'meta-image']),
    ]);

    app()->call([new ReplyToConverseTurn($message), 'handle']);
    app()->call([new ReplyToConverseTurn($message), 'handle']);
    $this->assertDatabaseHas('cv_messages', ['conversation_id' => $thread, 'sender_type' => 'ai',
        'outbound_asset_id' => $asset, 'delivery_status' => 'accepted']);
    Http::assertSentCount($whatsapp ? 3 : 4);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/messages') && ($whatsapp
        ? ($r['image']['id'] ?? null) === '1001' && filled($r['image']['caption'])
        : ($r['message']['attachment']['type'] ?? null) === 'image'));
})->with(['messenger', 'whatsapp']);

it('rejects arbitrary URLs, unsupported file bytes before provider calls', function () {
    [, $thread] = converseAttachmentThread('whatsapp');
    DB::table('cv_conversations')->update(['mode' => 'human']);
    $this->actingAs(User::factory()->create()->assignRole('staff'));
    $url = route('converse.inbox.reply', $thread);
    $base = ['operation_id' => (string) Str::uuid()];
    $this->post($url, [...$base, 'attachment' => 'https://example.invalid/private.pdf'])->assertSessionHasErrors('attachment');
    $this->post($url, [...$base, 'catalog_attachment_id' => 999])->assertSessionHasErrors('catalog_attachment_id');
    $this->post($url, [...$base, 'attachment' => UploadedFile::fake()->createWithContent('spoofed.pdf', '<html>test</html>')])
        ->assertSessionHasErrors('attachment');
    Http::assertNothingSent();
});
