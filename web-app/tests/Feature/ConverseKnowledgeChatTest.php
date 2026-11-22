<?php

use App\Actions\ComposeConverseReply;
use App\Actions\WorkspaceSettings;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite()->seed(DatabaseSeeder::class);
    config(['converse.ai.embedding_model' => 'fixture-embed', 'converse.ai.ollama_model' => 'fixture-chat']);
    Http::preventStrayRequests();
});

function knowledgeChatCollection(string $name, ?array $keywords = null): int
{
    return DB::table('cv_knowledge_collections')->insertGetId([
        'name' => $name, 'enabled' => true,
        ...($keywords !== null ? ['keywords' => json_encode($keywords)] : []),
    ]);
}

function knowledgeChatEntry(int $collection, string $title, string $body): int
{
    return DB::table('cv_knowledge_entries')->insertGetId([
        'collection_id' => $collection, 'kind' => 'faq', 'language' => 'en',
        'title' => $title, 'body' => $body, 'enabled' => true, 'public' => true,
    ]);
}

it('answers the test chat from all collections and reports its sources', function () {
    $first = knowledgeChatCollection('Products');
    $second = knowledgeChatCollection('Services');
    knowledgeChatEntry($first, 'Product fact', 'The DT widget ships in two sizes.');
    knowledgeChatEntry($second, 'Service fact', 'Installation visits happen on Saturdays.');

    Http::fake([
        'http://127.0.0.1:11434/api/embed' => fn (Request $request) => Http::response(['embeddings' => array_fill(0, count($request['input']), [1, 0])]),
        'http://127.0.0.1:11434/api/chat' => function (Request $request) {
            $sources = json_decode($request['messages'][1]['content'], true)['sources'];

            return Http::response(['done' => true, 'done_reason' => 'stop', 'message' => [
                'content' => json_encode(['source_id' => $sources[0]['id'], 'confidence' => 0.99]),
            ]]);
        },
    ]);

    $response = $this->actingAs(User::factory()->create()->assignRole('administrator'))
        ->postJson('/converse/knowledge/chat', ['message' => 'How does installation work?'])
        ->assertOk()
        ->assertJsonPath('reply.decision', 'send')
        ->assertJsonPath('reply.provider', 'ollama');

    // The most recently created entry is offered first.
    expect($response->json('reply.sources.0.title'))->toBe('Service fact');
});

it('scopes the chat to a single collection when requested', function () {
    $first = knowledgeChatCollection('Products');
    $second = knowledgeChatCollection('Services');
    knowledgeChatEntry($first, 'Product fact', 'The DT widget ships in two sizes.');
    knowledgeChatEntry($second, 'Service fact', 'Installation visits happen on Saturdays.');

    Http::fake([
        'http://127.0.0.1:11434/api/embed' => fn (Request $request) => Http::response(['embeddings' => array_fill(0, count($request['input']), [1, 0])]),
        'http://127.0.0.1:11434/api/chat' => function (Request $request) {
            $sources = json_decode($request['messages'][1]['content'], true)['sources'];
            expect($sources)->toHaveCount(1)->and($sources[0]['title'])->toBe('Service fact');

            return Http::response(['done' => true, 'done_reason' => 'stop', 'message' => [
                'content' => json_encode(['source_id' => $sources[0]['id'], 'confidence' => 0.99]),
            ]]);
        },
    ]);

    $this->actingAs(User::factory()->create()->assignRole('administrator'))
        ->postJson('/converse/knowledge/chat', ['message' => 'How does installation work?', 'collection' => $second])
        ->assertOk()
        ->assertJsonPath('reply.sources.0.title', 'Service fact');
});

it('scopes knowledge by collection keywords and falls back to everything', function () {
    $products = knowledgeChatCollection('Products', ['widget']);
    $services = knowledgeChatCollection('Services');
    knowledgeChatEntry($products, 'Widget fact', 'The DT widget ships in two sizes.');
    knowledgeChatEntry($services, 'Visit fact', 'Installation visits happen on Saturdays.');

    Http::fake([
        'http://127.0.0.1:11434/api/embed' => fn (Request $request) => Http::response(['embeddings' => array_fill(0, count($request['input']), [1, 0])]),
        'http://127.0.0.1:11434/api/chat' => function (Request $request) {
            $input = json_decode($request['messages'][1]['content'], true);
            $sources = $input['sources'];
            if (str_contains($input['history'][0]['text'] ?? '', 'widget')) {
                expect($sources)->toHaveCount(1)->and($sources[0]['title'])->toBe('Widget fact');
            } else {
                expect($sources)->toHaveCount(2);
            }

            return Http::response(['done' => true, 'done_reason' => 'stop', 'message' => [
                'content' => json_encode(['source_id' => $sources[0]['id'], 'confidence' => 0.99]),
            ]]);
        },
    ]);

    // Keyword present: only the matching collection's knowledge is offered.
    $this->actingAs(User::factory()->create()->assignRole('administrator'))
        ->postJson('/converse/knowledge/chat', ['message' => 'Tell me about the widget sizes'])
        ->assertOk()->assertJsonPath('reply.sources.0.title', 'Widget fact');

    // Without a keyword every collection stays eligible.
    $this->actingAs(User::factory()->create()->assignRole('administrator'))
        ->postJson('/converse/knowledge/chat', ['message' => 'How does installation work?'])
        ->assertOk();
});

it('answers matching questions from trained corrections before the model runs', function () {
    $collection = knowledgeChatCollection('Products');
    DB::table('cv_knowledge_corrections')->insert([
        'collection_id' => null, 'owner_id' => User::factory()->create()->id,
        'question' => 'do you support bangla', 'answer' => 'Yes, DT ERP answers in Bangla, English and Banglish.',
        'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    Http::fake();

    $this->actingAs(User::factory()->create()->assignRole('administrator'))
        ->postJson('/converse/knowledge/chat', ['message' => 'Hello, do you support bangla language?'])
        ->assertOk()
        ->assertJsonPath('reply.decision', 'send')
        ->assertJsonPath('reply.correction', true)
        ->assertJsonPath('reply.body', 'Yes, DT ERP answers in Bangla, English and Banglish.');
    Http::assertNothingSent();
});

it('adapts an approved reply to the workspace language and restores protected values', function (bool $valid) {
    $admin = User::factory()->create()->assignRole('administrator');
    $collection = knowledgeChatCollection('Products');
    app(WorkspaceSettings::class)->put('converse', ['reply_language' => 'bn']);
    DB::table('cv_knowledge_corrections')->insert([
        'collection_id' => $collection, 'owner_id' => $admin->id,
        'question' => 'installation booking', 'answer' => 'Email sales@example.invalid. The fee is 1,000 BDT.',
        'follow_up' => 'আর কোনো প্রশ্ন থাকলে বলুন।', 'enabled' => true,
    ]);
    Http::fake(['http://127.0.0.1:11434/api/chat' => function (Request $request) use ($valid) {
        $input = json_decode($request['messages'][1]['content'], true);
        expect($input['messages'])->toEqual(['Email [[DT0]]. The fee is [[DT1]] [[DT2]].']);
        expect($request['format']['properties'])->toHaveKey('messages');

        return Http::response(['done' => true, 'done_reason' => 'stop', 'message' => [
            'content' => json_encode(['messages' => [$valid
                ? 'ইমেইল করুন [[DT0]] ঠিকানায়। ফি [[DT1]] [[DT2]]।'
                : 'ইমেইল করুন [[DT0]] ঠিকানায়। ফি 900 [[DT2]]।']]),
        ]]);
    }]);

    $response = $this->actingAs($admin)->postJson(route('converse.playground.run'), [
        'mode' => 'knowledge', 'language' => 'en', 'collection' => $collection, 'message' => 'How do I request installation booking?',
    ])->assertOk()->assertJsonPath('draft.language', 'bn')
        ->assertJsonPath('draft.decision', $valid ? 'send' : 'handoff')
        ->assertJsonPath('attempts.0.operation', 'translation');
    if ($valid) {
        $response->assertJsonPath('draft.messages', [
            'ইমেইল করুন sales@example.invalid ঠিকানায়। ফি 1,000 BDT।',
            'আর কোনো প্রশ্ন থাকলে বলুন।',
        ]);
    } else {
        $response->assertDontSee('900');
    }

    $this->assertDatabaseHas('cv_ai_calls', ['operation' => 'translation', 'origin' => 'playground',
        'status' => $valid ? 'accepted' : 'rejected']);
})->with([true, false]);

it('selects an approved source in another language before rendering an English-only reply', function () {
    $collection = knowledgeChatCollection('Fixture products');
    $source = DB::table('cv_knowledge_entries')->insertGetId([
        'collection_id' => $collection, 'kind' => 'faq', 'language' => 'bn',
        'title' => 'ক্যাশ মিলানো', 'body' => 'দোকান বন্ধের সময় ক্যাশ গুনে হিসাব মিলিয়ে নিন।',
        'enabled' => true, 'public' => true,
    ]);
    app(WorkspaceSettings::class)->put('converse', ['reply_language' => 'en']);
    Http::fake([
        'http://127.0.0.1:11434/api/embed' => Http::response(['embeddings' => [[1, 0]]]),
        'http://127.0.0.1:11434/api/chat' => Http::sequence()
            ->push(['done' => true, 'done_reason' => 'stop', 'message' => [
                'content' => json_encode(['source_ids' => [$source], 'confidence' => 0.99]),
            ]])
            ->push(['done' => true, 'done_reason' => 'stop', 'message' => [
                'content' => '{"messages":["Count and reconcile the cash when the store closes."]}',
            ]]),
    ]);

    $this->actingAs(User::factory()->create()->assignRole('administrator'))
        ->postJson(route('converse.playground.run'), [
            'mode' => 'knowledge', 'language' => 'bn', 'collection' => $collection,
            'message' => 'দোকান বন্ধ হলে ক্যাশ কীভাবে মিলাব?',
        ])->assertOk()
        ->assertJsonPath('draft.language', 'en')
        ->assertJsonPath('draft.sources.0.id', $source)
        ->assertJsonPath('draft.text', 'Count and reconcile the cash when the store closes.');
    Http::assertSentCount(3);
});

it('answers from the most specific approved question in the named collection', function (string $question, string $answer) {
    $restora = knowledgeChatCollection('DT Restora', ['restora', 'রেস্টোরা', 'রেস্টোরার']);
    $other = knowledgeChatCollection('Other software', ['other software']);
    $admin = User::factory()->create()->assignRole('administrator');

    foreach ([
        [null, 'sales email', 'Contact the general sales team.'],
        [$other, 'sales email', 'Email other@example.invalid.'],
        [$restora, 'sales email | email address', 'You can email DT Restora sales at sales@example.invalid.'],
        [$restora, 'সেলস ইমেইল', 'সেলস টিমকে sales@example.invalid ঠিকানায় ইমেইল করতে পারেন।'],
        [$restora, 'demo free', 'Yes, the DT Restora demo is free.'],
        [$restora, 'monthly price', 'The monthly price is 1,000 BDT per branch.'],
        [$restora, 'price', 'See the DT Restora price plan.'],
    ] as [$collection, $phrase, $response]) {
        DB::table('cv_knowledge_corrections')->insert([
            'owner_id' => $admin->id, 'collection_id' => $collection, 'question' => $phrase, 'answer' => $response, 'enabled' => true,
        ]);
    }
    Http::fake();

    $this->actingAs($admin)->postJson('/converse/knowledge/chat', ['message' => $question])
        ->assertOk()
        ->assertJsonPath('reply.decision', 'send')
        ->assertJsonPath('reply.provider', 'correction')
        ->assertJsonPath('reply.body', $answer);
    Http::assertNothingSent();
})->with([
    ['give me sales email for dt-restora', 'You can email DT Restora sales at sales@example.invalid.'],
    ['What is the DT Restora sales e-mail?', 'You can email DT Restora sales at sales@example.invalid.'],
    ['What is the email address for DT Restora?', 'You can email DT Restora sales at sales@example.invalid.'],
    ['রেস্টোরার সেলস ইমেইল কী?', 'সেলস টিমকে sales@example.invalid ঠিকানায় ইমেইল করতে পারেন।'],
    ['Is the DT Restora demo free?', 'Yes, the DT Restora demo is free.'],
    ['What is the DT Restora monthly price?', 'The monthly price is 1,000 BDT per branch.'],
]);

it('uses approved corrections only for whole phrases and preserves human handoff', function (string $question, bool $selected) {
    $restora = knowledgeChatCollection('DT Restora', ['restora']);
    knowledgeChatCollection('Other software', ['other software']);
    $admin = User::factory()->create()->assignRole('administrator');
    DB::table('cv_knowledge_corrections')->insert([
        'owner_id' => $admin->id, 'collection_id' => $restora, 'question' => 'sales email',
        'answer' => 'Email the DT Restora sales team.', 'enabled' => true,
    ]);
    Http::fake();

    $this->actingAs($admin)->postJson('/converse/knowledge/chat', [
        'message' => $question, 'collection' => $selected ? $restora : null,
    ])->assertOk()->assertJsonPath('reply.decision', 'handoff');
})->with([
    ['give me a sales email', false],
    ['has the team emailed sales emails to everyone', true],
    ['I want to talk to a human about the sales email', true],
    ['I want to buy DT Restora, give me the sales email', true],
]);

it('keeps the product in scope for a follow-up and respects an explicit switch', function () {
    $restora = knowledgeChatCollection('DT Restora', ['restora']);
    $other = knowledgeChatCollection('Other software', ['other software']);
    $admin = User::factory()->create()->assignRole('administrator');
    foreach ([$restora => 'restora@example.invalid', $other => 'other@example.invalid'] as $collection => $address) {
        DB::table('cv_knowledge_corrections')->insert([
            'owner_id' => $admin->id, 'collection_id' => $collection, 'question' => 'sales email',
            'answer' => $address, 'enabled' => true,
        ]);
    }
    Http::fake();
    $history = collect([
        (object) ['direction' => 'in', 'body' => 'I am asking about DT Restora'],
        (object) ['direction' => 'out', 'body' => 'How can I help?'],
        (object) ['direction' => 'in', 'body' => 'And the sales email?'],
    ]);
    $settings = ['collections' => [$restora, $other], 'agent' => 'sales'];
    $compose = app(ComposeConverseReply::class);
    expect($compose($history, $settings)['body'])->toBe('restora@example.invalid');
    $history->push((object) ['direction' => 'in', 'body' => 'What is the sales email for Other Software?']);
    expect($compose($history, $settings)['body'])->toBe('other@example.invalid');
    Http::assertNothingSent();
});

it('keeps a named service in scope and answers two distinct questions from its published prices', function (bool $playground) {
    $collection = knowledgeChatCollection('Software and services');
    $product = DB::table('cv_knowledge_entries')->insertGetId([
        'collection_id' => $collection, 'kind' => 'product', 'title' => 'DT Widget',
        'language' => 'en', 'enabled' => true, 'public' => true,
    ]);
    $service = DB::table('cv_knowledge_entries')->insertGetId([
        'collection_id' => $collection, 'kind' => 'service', 'title' => 'Managed Setup',
        'language' => 'en', 'enabled' => true, 'public' => true,
    ]);
    foreach ([
        [$product, 'Widget monthly plan', 'monthly', 100],
        [$service, 'Managed Setup monthly plan', 'monthly', 1200],
        [$service, 'Managed Setup one-time installation', 'one_time', 2500],
    ] as [$subject, $title, $type, $amount]) {
        $prices[$title] = DB::table('cv_knowledge_entries')->insertGetId([
            'collection_id' => $collection, 'subject_id' => $subject, 'kind' => 'price', 'title' => $title,
            'language' => 'en', 'amount_type' => $type, 'amount' => $amount, 'currency' => 'BDT', 'enabled' => true, 'public' => true,
        ]);
    }
    $monthly = $prices['Managed Setup monthly plan'];
    $setup = $prices['Managed Setup one-time installation'];
    Http::fake(['http://127.0.0.1:11434/api/chat' => function (Request $request) use ($monthly, $setup) {
        $input = json_decode($request['messages'][1]['content'], true);
        expect(array_column($input['sources'], 'id'))->toEqualCanonicalizing([$monthly, $setup]);

        return Http::response(['done' => true, 'done_reason' => 'stop', 'message' => [
            'content' => json_encode(['source_ids' => [$monthly, $setup], 'confidence' => 0.99, 'ask_follow_up' => true]),
        ]]);
    }]);

    $reply = $playground ? 'draft' : 'reply';
    $this->actingAs(User::factory()->create()->assignRole('administrator'))
        ->postJson($playground ? route('converse.playground.run') : '/converse/knowledge/chat', [
            ...($playground ? ['mode' => 'knowledge', 'language' => 'en'] : []),
            'message' => 'What is the monthly price and installation fee?',
            'history' => [
                ['direction' => 'in', 'body' => 'Tell me about DT Widget'],
                ['direction' => 'in', 'body' => 'I am asking about Managed Setup now'],
            ],
        ])->assertOk()
        ->assertJsonPath($reply.'.scope.type', 'service')
        ->assertJsonPath($reply.'.scope.subject_id', $service)
        ->assertJsonPath($reply.'.messages', [
            'Managed Setup price: 1200 BDT per month',
            "Managed Setup price: 2500 BDT one time\n\nIs there anything else I can help with?",
        ]);
})->with([false, true]);

it('manages corrections with validation, scoping and permissions', function () {
    $admin = User::factory()->create()->assignRole('administrator');
    $this->actingAs($admin);

    $this->post('/converse/knowledge/corrections', [
        'question' => 'ab', 'answer' => 'x',
    ])->assertSessionHasErrors(['question', 'answer']);

    $this->post('/converse/knowledge/corrections', [
        'question' => 'refund policy?', 'answer' => 'Refunds are processed within 7 working days.',
    ])->assertRedirect();
    $correction = DB::table('cv_knowledge_corrections')->sole();

    $this->patch("/converse/knowledge/corrections/{$correction->id}", ['enabled' => false])->assertRedirect();
    $this->assertDatabaseHas('cv_knowledge_corrections', ['id' => $correction->id, 'enabled' => false]);

    $this->delete("/converse/knowledge/corrections/{$correction->id}")->assertRedirect();
    $this->assertDatabaseMissing('cv_knowledge_corrections', ['id' => $correction->id]);

    $this->actingAs(User::factory()->create()->assignRole('staff'))
        ->post('/converse/knowledge/corrections', ['question' => 'refund policy?', 'answer' => 'no'])->assertForbidden();
});

it('stores collection keywords through the collection form', function () {
    $this->actingAs(User::factory()->create()->assignRole('administrator'));

    $this->post('/converse/knowledge/collections', [
        'name' => 'Products', 'enabled' => true, 'keywords' => ['widget', 'pos'],
    ])->assertRedirect();
    $this->assertDatabaseHas('cv_knowledge_collections', ['name' => 'Products', 'keywords' => '["widget","pos"]']);

    $id = DB::table('cv_knowledge_collections')->where('name', 'Products')->value('id');
    $this->patch("/converse/knowledge/collections/{$id}", [
        'name' => 'Products', 'enabled' => true, 'keywords' => [],
    ])->assertRedirect();
    $this->assertDatabaseHas('cv_knowledge_collections', ['id' => $id, 'keywords' => null]);
});
