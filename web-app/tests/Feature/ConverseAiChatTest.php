<?php

use App\Actions\RecordConverseInboundMessage;
use App\Jobs\ReplyToConverseTurn;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'converse.ai.auto_reply' => true,
        'converse.outbound_enabled' => true,
        'converse.ai.ollama_model' => 'fixture',
        'converse.ai.allow_cloud' => true,
        'converse.ai.openai_model' => 'cloud-fixture',
        'converse.ai.openai_key' => 'unused-fixture',
    ]);
});

function aiChatFixture(string $channel = 'messenger'): array
{
    $account = DB::table('cv_channel_accounts')->insertGetId([
        'type' => $channel, 'name' => 'Fixture', 'external_id' => '101',
        'send_token' => Crypt::encryptString('fake-page-token'),
    ]);
    $collection = DB::table('cv_knowledge_collections')->insertGetId(['name' => 'Fixture', 'enabled' => true]);
    config(['converse.ai.channels' => [$account => ['enabled' => true, 'collections' => [$collection]]]]);

    return [$account, $collection];
}

function aiChatInbound(int $account, string $message, string $body): int
{
    return app(RecordConverseInboundMessage::class)($account, '8801000000202', $message, $body, now());
}

it('combines recent inbound context and sends one verified structured price in the latest language', function (string $question, string $language, string $amount, string $channel) {
    [$account, $collection] = aiChatFixture($channel);
    $product = DB::table('cv_knowledge_entries')->insertGetId([
        'collection_id' => $collection, 'kind' => 'product', 'title' => 'ERP', 'language' => 'en', 'enabled' => true, 'public' => true,
    ]);
    $price = DB::table('cv_knowledge_entries')->insertGetId([
        'collection_id' => $collection, 'subject_id' => $product, 'kind' => 'price', 'title' => 'ERP subscription', 'language' => 'en',
        'amount_type' => 'monthly', 'currency' => 'BDT', 'amount' => 1200, 'enabled' => true, 'public' => true,
    ]);
    DB::table('cv_knowledge_entries')->insert([
        'collection_id' => $collection, 'subject_id' => $product, 'kind' => 'price', 'title' => 'Expired special',
        'language' => 'en', 'amount_type' => 'fixed', 'amount' => 7, 'enabled' => true, 'public' => true,
        'valid_to' => now('Asia/Dhaka')->subDay()->toDateString(),
    ]);
    $model = 'http://127.0.0.1:11434/api/chat';
    Http::fake([
        $model => Http::response(['done' => true, 'done_reason' => 'stop',
            'message' => ['content' => json_encode(['source_id' => $price, 'confidence' => 0.99, 'reply' => 'Invented 500 BDT'])]]),
        'https://graph.facebook.com/*/101/messages' => Http::response(['message_id' => 'sent', 'messages' => [['id' => 'sent']]]),
    ]);

    $earlier = aiChatInbound($account, 'm1', 'I need ERP');
    $latest = aiChatInbound($account, 'm2', $question);
    dispatch_sync(new ReplyToConverseTurn($earlier));
    dispatch_sync(new ReplyToConverseTurn($latest));
    dispatch_sync(new ReplyToConverseTurn($latest));

    $this->assertDatabaseCount('cv_ai_turns', 1);
    $this->assertDatabaseHas('cv_ai_turns', ['inbound_message_id' => $latest, 'language' => $language, 'provider' => 'ollama', 'decision' => 'send']);
    $body = DB::table('cv_messages')->where('sender_type', 'ai')->where('delivery_status', 'accepted')->soleValue('body');
    expect($body)->toContain($amount)->not->toContain('500');
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request->url() === $model
        && str_contains($request['messages'][1]['content'], 'I need ERP')
        && ! str_contains($request['messages'][1]['content'], 'Expired special'));
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'graph.facebook.com')
        && $request->hasHeader('Authorization', 'Bearer fake-page-token')
        && ($channel === 'messenger' ? $request['message']['text'] : $request['text']['body']) === $body);
})->with([
    ['How much is the price?', 'en', '1200 BDT per month', 'messenger'],
    ['ar price koto?', 'banglish', '1200 BDT proti mashe', 'messenger'],
    ['১ মাসের দাম কত?', 'bn', '১২০০ BDT প্রতি মাসে', 'whatsapp'],
]);

it('hands off when the model is unavailable or chooses an unapproved source and keeps live history local', function (bool $unavailable) {
    [$account, $collection] = aiChatFixture();
    DB::table('cv_knowledge_entries')->insert([
        'collection_id' => $collection, 'kind' => 'faq', 'title' => 'ERP', 'body' => 'ERP tracks business workflows.',
        'language' => 'en', 'enabled' => true, 'public' => true,
    ]);
    Http::fake([
        'http://127.0.0.1:11434/*' => $unavailable ? Http::response([], 503) : Http::response([
            'done' => true, 'done_reason' => 'stop', 'message' => ['content' => '{"source_id":999,"confidence":1}'],
        ]),
        'https://graph.facebook.com/*' => Http::response(['message_id' => 'handoff']),
    ]);

    dispatch_sync(new ReplyToConverseTurn(aiChatInbound($account, 'm1', 'Do you make ERP?')));

    $this->assertDatabaseHas('cv_conversations', ['mode' => 'human', 'status' => 'pending']);
    $this->assertDatabaseHas('cv_ai_turns', ['decision' => 'handoff']);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'api.openai.com'));
})->with([true, false]);

it('blocks automatic sends for takeover, opt-out and the kill switch', function (string $reason) {
    [$account] = aiChatFixture();
    $message = aiChatInbound($account, 'm1', $reason === 'opt-out' ? 'unsubscribe' : 'hello');
    if ($reason === 'takeover') {
        DB::table('cv_conversations')->update(['mode' => 'human']);
    }
    if ($reason === 'kill switch') {
        config(['converse.ai.auto_reply' => false]);
    }

    dispatch_sync(new ReplyToConverseTurn($message));

    $this->assertDatabaseCount('cv_ai_turns', 0);
    Http::assertNothingSent();
})->with(['takeover', 'opt-out', 'kill switch']);

it('uses a configured local fallback model without cloud disclosure', function () {
    [$account, $collection] = aiChatFixture();
    config(['converse.ai.ollama_fallback_model' => 'fixture-fallback']);
    $entry = DB::table('cv_knowledge_entries')->insertGetId([
        'collection_id' => $collection, 'kind' => 'faq', 'title' => 'ERP', 'body' => 'ERP tracks business workflows.',
        'language' => 'en', 'enabled' => true, 'public' => true,
    ]);
    Http::fake([
        'http://127.0.0.1:11434/*' => Http::sequence()->pushStatus(503)->push([
            'done' => true, 'done_reason' => 'stop', 'message' => ['content' => json_encode(['source_id' => $entry, 'confidence' => 0.95])],
        ]),
        'https://graph.facebook.com/*' => Http::response(['message_id' => 'reply']),
    ]);

    dispatch_sync(new ReplyToConverseTurn(aiChatInbound($account, 'm1', 'Do you make ERP?')));

    $this->assertDatabaseHas('cv_ai_turns', ['model' => 'fixture-fallback', 'fallback' => true, 'decision' => 'send']);
    $this->assertDatabaseHas('cv_messages', ['body' => 'ERP tracks business workflows.', 'delivery_status' => 'accepted']);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '127.0.0.1') && $request['model'] === 'fixture-fallback');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'api.openai.com'));
});

it('checks human takeover again after model generation', function () {
    [$account, $collection] = aiChatFixture();
    $entry = DB::table('cv_knowledge_entries')->insertGetId([
        'collection_id' => $collection, 'kind' => 'faq', 'title' => 'ERP', 'body' => 'Approved answer.',
        'language' => 'en', 'enabled' => true, 'public' => true,
    ]);
    Http::fake(['http://127.0.0.1:11434/*' => function () use ($entry) {
        DB::table('cv_conversations')->update(['mode' => 'human']);

        return Http::response(['done' => true, 'done_reason' => 'stop',
            'message' => ['content' => json_encode(['source_id' => $entry, 'confidence' => 0.95])]]);
    }]);

    dispatch_sync(new ReplyToConverseTurn(aiChatInbound($account, 'm1', 'Tell me about ERP')));

    Http::assertSentCount(1);
    $this->assertDatabaseMissing('cv_messages', ['direction' => 'out']);
    $this->assertDatabaseHas('cv_conversations', ['mode' => 'human', 'status' => 'open']);
});

it('hands off an uncertain delivery without attempting a second provider send', function () {
    [$account] = aiChatFixture();
    Http::fake(['https://graph.facebook.com/*' => Http::failedConnection()]);

    $message = aiChatInbound($account, 'm1', 'hello');
    dispatch_sync(new ReplyToConverseTurn($message));
    dispatch_sync(new ReplyToConverseTurn($message));

    $this->assertDatabaseCount('cv_ai_turns', 1);
    $this->assertDatabaseHas('cv_messages', ['direction' => 'out', 'delivery_status' => 'unknown']);
    $this->assertDatabaseHas('cv_conversations', ['mode' => 'human', 'status' => 'pending']);
});

it('creates an editable Inbox draft without sending a message or claiming a turn', function () {
    [$account, $collection] = aiChatFixture();
    config(['converse.ai.auto_reply' => false]);
    $entry = DB::table('cv_knowledge_entries')->insertGetId([
        'collection_id' => $collection, 'kind' => 'faq', 'title' => 'ERP', 'body' => 'ERP tracks business workflows.',
        'language' => 'en', 'enabled' => true, 'public' => true,
    ]);
    Http::fake(['http://127.0.0.1:11434/*' => Http::response([
        'done' => true, 'done_reason' => 'stop',
        'message' => ['content' => json_encode(['source_id' => $entry, 'confidence' => 0.95])],
    ])]);
    $message = aiChatInbound($account, 'm1', 'Tell me about ERP');
    $thread = DB::table('cv_messages')->where('id', $message)->value('conversation_id');
    $this->seed(DatabaseSeeder::class);

    $this->actingAs(User::factory()->create()->assignRole('staff'))
        ->postJson(route('converse.inbox.draft', $thread))->assertOk()
        ->assertJsonPath('draft.body', 'ERP tracks business workflows.');
    $this->assertDatabaseCount('cv_ai_turns', 0);
    $this->assertDatabaseCount('cv_messages', 1);
    Http::assertSentCount(1);
});

it('ignores an out-of-order webhook when a newer customer message already exists', function () {
    [$account] = aiChatFixture();
    $record = app(RecordConverseInboundMessage::class);
    $newer = $record($account, '202', 'newer', 'hello', now());
    $delayed = $record($account, '202', 'older', 'hello', now()->subMinutes(2));
    Http::fake(['https://graph.facebook.com/*' => Http::response(['message_id' => 'accepted'])]);

    dispatch_sync(new ReplyToConverseTurn($delayed));
    dispatch_sync(new ReplyToConverseTurn($newer));
    dispatch_sync(new ReplyToConverseTurn($delayed));

    Http::assertSentCount(1);
    $this->assertDatabaseCount('cv_ai_turns', 1);
    $this->assertDatabaseHas('cv_ai_turns', ['inbound_message_id' => $newer]);
});
