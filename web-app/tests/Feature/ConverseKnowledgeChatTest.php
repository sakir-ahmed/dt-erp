<?php

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
