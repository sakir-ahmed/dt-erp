<?php

use App\Jobs\IndexConverseDocument;
use App\Jobs\ProcessConverseWebhook;
use App\Jobs\ReplyToConverseTurn;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('runs a signed inbound webhook through indexed mixed-language knowledge, AI and a simulated provider', function (string $channel) {
    $this->seed(DatabaseSeeder::class);
    $this->freezeTime();
    Queue::fake();
    Storage::fake('local');

    $account = DB::table('cv_channel_accounts')->insertGetId([
        'type' => $channel, 'name' => 'Fictional test channel', 'external_id' => '101',
        'send_token' => Crypt::encryptString('fixture-token'),
    ]);
    $collection = DB::table('cv_knowledge_collections')->insertGetId(['name' => 'Fictional test knowledge', 'enabled' => true]);
    $realOllama = getenv('CONVERSE_TEST_REAL_OLLAMA') === '1';
    $whatsapp = $channel === 'whatsapp';
    $provider = $whatsapp ? 'whatsapp' : 'meta';
    config([
        'inertia.ssr.enabled' => false,
        'converse.ai.auto_reply' => true, 'converse.outbound_enabled' => true,
        'converse.ai.embedding_model' => $realOllama ? 'paraphrase-multilingual' : 'fixture-embedding',
        'converse.ai.ollama_model' => $realOllama ? 'qwen2.5:1.5b' : 'fixture-chat',
        "converse.webhooks.{$provider}.secret" => 'signed-fixture-webhook-secret',
        'converse.ai.channels' => [$account => ['enabled' => true, 'agent' => 'sales', 'collections' => [$collection]]],
        'converse.ai.agents.sales.collections' => [$collection],
    ]);

    Http::preventStrayRequests();
    $graph = 'https://graph.facebook.com/*/101/messages';
    $fakes = [$graph => Http::response($whatsapp ? ['messages' => [['id' => 'wamid.simulated-confirmation']]] : ['message_id' => 'simulated-confirmation'])];
    if ($realOllama) {
        Http::allowStrayRequests(['http://127.0.0.1:11434/api/embed', 'http://127.0.0.1:11434/api/chat']);
    } else {
        $fakes['http://127.0.0.1:11434/api/embed'] = fn (Request $request) => Http::response(['embeddings' => array_fill(0, count($request['input']), [1, 0])]);
        $fakes['http://127.0.0.1:11434/api/chat'] = function (Request $request) {
            $sources = json_decode($request['messages'][1]['content'], true)['sources'];
            expect($sources)->toHaveCount(1);
            expect($sources[0]['description'])->toContain('groups work by status');

            return Http::response(['done' => true, 'done_reason' => 'stop', 'message' => [
                'content' => json_encode(['source_id' => $sources[0]['id'], 'confidence' => 0.99]),
            ]]);
        };
    }
    Http::fake($fakes);

    $admin = User::factory()->create()->assignRole('administrator');
    $upload = UploadedFile::fake()->createWithContent('fictional-project-board.md',
        "The fictional project board groups work by status.\n\nকাল্পনিক প্রজেক্ট বোর্ডে কাজ অবস্থা অনুযায়ী সাজানো হয়।");
    $this->actingAs($admin)->post(route('converse.knowledge.documents.store', $collection), ['document' => $upload])->assertRedirect();
    $document = DB::table('cv_knowledge_documents')->soleValue('id');
    app()->call([new IndexConverseDocument($document), 'handle']);
    $this->assertDatabaseHas('cv_knowledge_documents', ['id' => $document, 'language' => 'mixed', 'status' => 'indexed']);
    $this->patch(route('converse.knowledge.documents.update', [$collection, $document]), [
        'enabled' => true, 'public' => true,
    ])->assertRedirect();

    $signedMessage = function (string $id, string $sender, string $message, int $at) use ($whatsapp, $provider) {
        $payload = json_encode($whatsapp ? ['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => '901', 'changes' => [[
                'field' => 'messages', 'value' => [
                    'messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '101'],
                    'messages' => [['id' => $id, 'from' => $sender, 'type' => 'text',
                        'timestamp' => (string) intdiv($at, 1000), 'text' => ['body' => $message]]],
                ],
            ]],
        ]]] : ['object' => 'page', 'entry' => [[
            'id' => '101', 'messaging' => [[
                'sender' => ['id' => $sender], 'recipient' => ['id' => '101'], 'timestamp' => $at,
                'message' => ['mid' => $id, 'text' => $message],
            ]],
        ]]], JSON_THROW_ON_ERROR);
        $this->call('POST', "/api/v1/converse/webhooks/{$provider}", server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, 'signed-fixture-webhook-secret'),
        ], content: $payload)->assertOk();
        $event = DB::table('cv_webhook_events')->where('digest', hash('sha256', $payload))->value('id');
        app()->call([new ProcessConverseWebhook($event), 'handle']);

        return (int) DB::table('cv_messages')->where('external_id', $id)->value('id');
    };

    $time = now()->getTimestampMs();
    $question = 'How does the fictional project board organize work?';
    $message = $signedMessage('fixture-live', '8801000000201', $question, $time);
    $signedMessage('fixture-live', '8801000000201', $question, $time);
    Queue::assertPushedOn('converse-ai', ReplyToConverseTurn::class,
        fn (ReplyToConverseTurn $job) => $job->inboundMessageId === $message && $job->delay !== null);
    $job = new ReplyToConverseTurn($message);
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);

    $this->assertDatabaseHas('cv_ai_turns', ['inbound_message_id' => $message, 'provider' => 'ollama', 'decision' => 'send']);
    $this->assertDatabaseHas('cv_messages', [
        'body' => 'The fictional project board groups work by status.', 'direction' => 'out', 'delivery_status' => 'accepted',
    ]);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'graph.facebook.com')
        && ($whatsapp
            ? $request['to'] === '+8801000000201' && $request['text']['body'] === 'The fictional project board groups work by status.'
            : $request['recipient']['id'] === '8801000000201' && $request['message']['text'] === 'The fictional project board groups work by status.'));
    expect(DB::table('cv_notification_events')->where('type', 'ai_reply')->count())->toBe(1);
    $this->assertDatabaseCount('cv_ai_turns', 1);

    $old = $signedMessage('fixture-expired', '8801000000202', $question, now()->subHours(25)->getTimestampMs());
    app()->call([new ReplyToConverseTurn($old), 'handle']);
    $stop = $signedMessage('fixture-stop', '8801000000203', 'unsubscribe', $time);
    app()->call([new ReplyToConverseTurn($stop), 'handle']);
    $takeover = $signedMessage('fixture-human', '8801000000204', $question, $time);
    $thread = DB::table('cv_messages')->where('id', $takeover)->value('conversation_id');
    DB::table('cv_conversations')->where('id', $thread)->update(['mode' => 'human']);
    app()->call([new ReplyToConverseTurn($takeover), 'handle']);
    $this->assertDatabaseCount('cv_ai_turns', 1);
    expect(Http::recorded(fn (Request $request) => str_contains($request->url(), 'graph.facebook.com')))->toHaveCount(1);
})->with(['messenger', 'whatsapp']);
