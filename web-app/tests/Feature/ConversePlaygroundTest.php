<?php

use App\Actions\AiProviderCredentials;
use App\Actions\ConverseAiSettings;
use App\Actions\EmbedConverseText;
use App\Actions\RouteConverseDraft;
use App\Enums\WorkspaceRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite()->seed(DatabaseSeeder::class);
    config([
        'inertia.ssr.enabled' => false,
        'converse.ai.primary' => 'ollama',
        'converse.ai.ollama_model' => 'fixture:local',
        'converse.ai.openai_model' => 'fixture-model',
        'converse.ai.allow_cloud' => true,
    ]);
    app(AiProviderCredentials::class)->put(['openai' => 'fixture-openai-token']);
    Http::preventStrayRequests();
});

it('routes only synthetic content, falls back to OpenAI when permitted and returns routing provenance', function () {
    Http::fake([
        'http://127.0.0.1:11434/api/chat' => Http::response([], 503),
        'https://api.openai.com/v1/responses' => Http::response([
            'status' => 'completed',
            'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Thanks for reaching out.']]]],
            'usage' => ['input_tokens' => 40, 'output_tokens' => 10, 'total_tokens' => 50],
        ]),
    ]);

    $this->actingAs(User::factory()->create()->assignRole(WorkspaceRole::Administrator->value))
        ->postJson(route('converse.playground.run'), ['language' => 'en', 'local_only' => false, 'input' => 'Customer data'])
        ->assertOk()->assertJsonPath('draft.provider', 'openai')->assertJsonPath('draft.fallback', true);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'openai.com')
        && $request['input'] === 'Hello, could I speak to a team member?'
        && $request['store'] === false
        && $request->hasHeader('Authorization', 'Bearer fixture-openai-token'));
    $this->assertDatabaseHas('cv_ai_calls', ['origin' => 'playground', 'provider' => 'ollama', 'status' => 'unavailable',
        'http_status' => 503, 'total_tokens' => null, 'billing' => 'local']);
    $this->assertDatabaseHas('cv_ai_calls', ['origin' => 'playground', 'provider' => 'openai', 'status' => 'accepted',
        'http_status' => 200, 'input_tokens' => 40, 'output_tokens' => 10, 'total_tokens' => 50, 'billing' => 'paid', 'reported_cost_usd' => null]);
});

it('enforces local-only mode and demo isolation even when cloud is configured', function () {
    Http::fake(['http://127.0.0.1:11434/api/chat' => Http::response([], 503)]);
    $admin = User::factory()->create()->assignRole(WorkspaceRole::Administrator->value);
    $demo = User::factory()->create(['is_demo' => true])->assignRole(WorkspaceRole::Administrator->value);

    $this->actingAs($admin)->postJson(route('converse.playground.run'), ['language' => 'bn', 'local_only' => true])
        ->assertOk()->assertJsonPath('draft', null);
    $this->actingAs($demo)->postJson(route('converse.playground.run'), ['language' => 'banglish', 'local_only' => false])
        ->assertOk()->assertJsonPath('draft', null);

    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'openai.com'));
});

it('returns a usable local draft and refuses the playground to staff', function () {
    Http::fake(['http://127.0.0.1:11434/api/chat' => Http::response([
        'done' => true, 'done_reason' => 'stop', 'message' => ['content' => 'যোগাযোগের জন্য ধন্যবাদ।'],
    ])]);
    $url = route('converse.playground.run');
    $input = ['language' => 'bn', 'local_only' => true];

    $this->actingAs(User::factory()->create()->assignRole(WorkspaceRole::Staff->value))
        ->postJson($url, $input)->assertForbidden();
    $this->actingAs(User::factory()->create()->assignRole(WorkspaceRole::Administrator->value))
        ->postJson($url, $input)->assertOk()->assertJsonPath('draft.provider', 'ollama')->assertJsonPath('draft.fallback', false);
    Http::assertSentCount(1);
});

it('shows filtered call statistics and provides redacted details only to settings administrators', function () {
    $secret = 'fixture-router-credential';
    app(AiProviderCredentials::class)->put(['openrouter' => $secret]);
    config(['converse.ai.cloud.models' => [
        ['provider' => 'openrouter', 'model' => 'fixture/free:free', 'tier' => 'free'],
        ['provider' => 'openrouter', 'model' => 'fixture/economy', 'tier' => 'economy'],
    ]]);
    Http::fake(['https://openrouter.ai/api/v1/chat/completions' => Http::sequence()
        ->push([
            'model' => 'fixture/free',
            'choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'Answer rejected by the validator.']]],
            'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 9, 'cost' => 0],
        ])
        ->push([
            'model' => 'fixture/economy',
            'choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'A grounded reply.']]],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10, 'total_tokens' => 30, 'cost' => 0.00012],
        ])]);
    app(RouteConverseDraft::class)('Support instructions', json_encode([
        'question' => "Contact example@example.test at +8801712345678; internal text {$secret}.",
        'memory' => 'Private memory',
    ]), localOnly: false, accept: fn ($text) => $text === 'A grounded reply.', cloudOnly: true);

    Http::assertSent(fn (Request $request) => $request['usage'] === ['include' => true]);
    $row = DB::table('cv_ai_calls')->where('billing', 'free')->sole();
    expect($row->total_tokens)->toBe(20);
    expect($row->detail)->not->toContain('Support instructions', 'example@example.test', $secret);
    $this->assertDatabaseHas('cv_ai_calls', ['provider' => 'openrouter', 'billing' => 'paid', 'reported_cost_usd' => 0.00012]);

    $index = route('converse.ai-calls.index', ['provider' => 'openrouter', 'status' => 'rejected']);
    $show = route('converse.ai-calls.show', $row->id);
    foreach ([
        User::factory()->create()->assignRole(WorkspaceRole::Staff->value),
        User::factory()->create(['is_demo' => true])->assignRole(WorkspaceRole::Administrator->value),
    ] as $user) {
        $this->actingAs($user)->get($index)->assertForbidden();
        $this->getJson($show)->assertForbidden();
    }

    $this->actingAs(User::factory()->create()->assignRole(WorkspaceRole::Administrator->value));
    $this->get($index)->assertInertia(fn ($page) => $page
        ->component('Converse/AiCalls')
        ->where('totals.calls', 1)->where('totals.free_calls', 1)->where('totals.tokens', 20)
        ->where('calls.data.0.id', $row->id)->missing('calls.data.0.detail'));

    $response = $this->getJson($show)->assertOk()->assertHeaderContains('Cache-Control', 'no-store')
        ->assertJsonPath('detail.instructions', 'Support instructions')
        ->assertJsonPath('detail.output', 'Answer rejected by the validator.');
    expect($response->json('detail.input'))->toContain('[redacted]')
        ->not->toContain('example@example.test', '+8801712345678', $secret, 'Private memory');
});

it('records Ollama embedding token usage without storing the vectors', function () {
    Http::fake(['http://127.0.0.1:11434/api/embed' => Http::response([
        'embeddings' => [[0.1, 0.2]], 'prompt_eval_count' => 12,
    ])]);
    expect(app(EmbedConverseText::class)(['Synthetic knowledge'], 'fixture-embed'))->toBe([[0.1, 0.2]]);
    $this->assertDatabaseHas('cv_ai_calls', [
        'operation' => 'embedding', 'origin' => 'knowledge', 'billing' => 'local', 'status' => 'accepted',
        'input_tokens' => 12, 'output_tokens' => 0, 'total_tokens' => 12,
    ]);
    expect(Crypt::decryptString(DB::table('cv_ai_calls')->soleValue('detail')))->not->toContain('0.1');
});

it('can disable and re-enable the local provider without disabling configured cloud tests', function () {
    $admin = User::factory()->create()->assignRole(WorkspaceRole::Administrator->value);
    $form = ['primary' => 'ollama', 'allow_cloud' => true, 'ollama_port' => 11434,
        'ollama_model' => 'fixture:local', 'remove_openai_key' => false];
    $this->actingAs($admin)->patch(route('settings.converse.providers'), [...$form, 'ollama_enabled' => false])->assertRedirect(route('settings.index'));
    $this->get(route('settings.index'))->assertInertia(fn ($page) => $page->where('converseAi.providerSettings.ollama_enabled', false));
    $this->get(route('converse.playground.index'))->assertInertia(fn ($page) => $page->where('ollamaAvailable', false)->missing('providerSettings'));
    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'status' => 'completed',
            'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Thanks for writing.']]]],
        ]),
        'http://127.0.0.1:11434/api/chat' => Http::response([
            'done' => true, 'done_reason' => 'stop', 'message' => ['content' => 'Thanks for writing.'],
        ]),
    ]);
    $run = route('converse.playground.run');
    $this->postJson($run, ['language' => 'en', 'local_only' => true])->assertJsonPath('draft', null);
    $this->postJson($run, ['language' => 'en', 'local_only' => false])->assertJsonPath('draft.provider', 'openai');
    Http::assertSentCount(1);

    $this->patch(route('converse.playground.providers'), [...$form, 'ollama_enabled' => true])->assertRedirect();
    $this->postJson($run, ['language' => 'en', 'local_only' => true])->assertJsonPath('draft.provider', 'ollama');
    Http::assertSentCount(2);
});

it('allows only a non-demo admin to save encrypted provider settings and never returns the stored key', function () {
    $testKey = 'synthetic-provider-secret';
    $settings = [
        'primary' => 'openai', 'allow_cloud' => true, 'ollama_port' => 11434, 'ollama_model' => 'fixture:local',
        'openai_model' => 'fixture-cloud', 'openai_key' => $testKey, 'remove_openai_key' => false,
    ];
    $url = route('converse.playground.providers');
    $staff = User::factory()->create()->assignRole(WorkspaceRole::Staff->value);
    $demo = User::factory()->create(['is_demo' => true])->assignRole(WorkspaceRole::Administrator->value);
    $admin = User::factory()->create()->assignRole(WorkspaceRole::Administrator->value);

    $this->actingAs($staff)->patch($url, $settings)->assertForbidden();
    $this->actingAs($demo)->patch($url, $settings)->assertForbidden();
    $this->actingAs($admin)->patch($url, $settings)->assertRedirect(route('converse.playground.index'));

    $ciphertext = json_decode(DB::table('settings')->where(['section' => 'ai_provider_credentials', 'key' => 'openai'])->value('value'), true);
    expect($ciphertext)->not->toBe($testKey);
    expect(Crypt::decryptString($ciphertext))->toBe($testKey);
    expect(DB::table('cv_audit_events')->where('action', 'ai.providers_saved')->soleValue('changes'))->not->toContain($testKey);
    $this->get(route('converse.playground.index'))->assertDontSee($testKey)->assertDontSee($ciphertext);

    $this->patch($url, [...$settings, 'openai_key' => '', 'remove_openai_key' => true])->assertRedirect();
    $this->assertDatabaseHas('settings', ['section' => 'ai_provider_credentials', 'key' => 'openai', 'value' => 'null']);
    $this->get(route('settings.index'))->assertInertia(
        fn ($page) => $page->where('converseAi.providerSettings.has_openai_key', false)->missing('converseAi.providerSettings.openai_key'),
    );
});

it('tests a selected cloud provider with a synthetic sample and skips a disabled provider', function () {
    $admin = User::factory()->create()->assignRole(WorkspaceRole::Administrator->value);
    app(AiProviderCredentials::class)->put(['openrouter' => 'fixture-router']);
    config(['converse.ai.cloud.models' => [
        ['provider' => 'openrouter', 'model' => 'fixture/free:free', 'tier' => 'free'],
        ['provider' => 'openai', 'model' => 'fixture-model', 'tier' => 'economy'],
    ]]);
    Http::fake(['https://openrouter.ai/api/v1/chat/completions' => Http::response([
        'choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'Happy to help.']]],
    ])]);

    $url = route('converse.playground.run');
    $payload = ['language' => 'en', 'provider_mode' => 'cloud', 'cloud_provider' => 'openrouter', 'message' => 'Private sample'];
    $this->actingAs($admin)->postJson($url, $payload)->assertOk()
        ->assertJsonPath('draft.provider', 'openrouter')->assertJsonStructure(['duration_ms']);
    Http::assertSent(fn (Request $request) => $request['messages'][1]['content'] === 'Hello, could I speak to a team member?');

    $this->patch(route('settings.ai-providers.update'), ['enable_openrouter' => false])->assertRedirect();
    $this->postJson($url, $payload)->assertOk()->assertJsonPath('draft', null);
    expect(app(AiProviderCredentials::class)->get()['openrouter'])->toBe('fixture-router');
    $this->get(route('settings.index'))->assertInertia(fn ($page) => $page
        ->where('aiProviders.0.configured', true)->where('aiProviders.0.enabled', false));
    Http::assertSentCount(1);
});

it('tests a local grounded reply with channel knowledge and returns named sources', function () {
    $admin = User::factory()->create()->assignRole(WorkspaceRole::Administrator->value);
    $demo = User::factory()->create(['is_demo' => true])->assignRole(WorkspaceRole::Administrator->value);
    $collection = DB::table('cv_knowledge_collections')->insertGetId(['name' => 'Fixture Knowledge', 'enabled' => true]);
    $outside = DB::table('cv_knowledge_collections')->insertGetId(['name' => 'Outside scope', 'enabled' => true]);
    $channel = DB::table('cv_channel_accounts')->insertGetId(['name' => 'Fixture', 'external_id' => '571', 'type' => 'whatsapp', 'enabled' => true]);
    $entry = DB::table('cv_knowledge_entries')->insertGetId([
        'collection_id' => $collection, 'title' => 'End a cashier shift', 'kind' => 'faq', 'language' => 'en',
        'body' => 'Count the drawer and compare it with the expected cash.', 'enabled' => true, 'public' => true,
    ]);
    config(['converse.ai.channels' => [$channel => ['collections' => [$collection]]], 'converse.ai.cloud.live' => true]);
    Http::fake([
        'http://127.0.0.1:11434/api/chat' => Http::response([
            'done' => true, 'done_reason' => 'stop',
            'message' => ['content' => json_encode(['source_ids' => [$entry], 'confidence' => 0.99])],
        ]),
    ]);
    $url = route('converse.playground.run');
    $payload = ['mode' => 'knowledge', 'language' => 'en', 'message' => 'How do I end a cashier shift?', 'channel' => $channel, 'collection' => $collection,
        'provider_mode' => 'cloud'];

    $this->actingAs($demo)->postJson($url, $payload)->assertForbidden();
    $this->actingAs($admin)->postJson($url, [...$payload, 'collection' => $outside])->assertUnprocessable()->assertJsonValidationErrors('collection');
    $this->postJson($url, $payload)->assertOk()
        ->assertJsonPath('draft.decision', 'send')->assertJsonPath('draft.scope.label', 'Fixture Knowledge')
        ->assertJsonPath('draft.sources.0.title', 'End a cashier shift')->assertJsonPath('draft.provider', 'ollama');
    Http::assertSentCount(1);
    $this->assertDatabaseCount('cv_messages', 0);
    $this->assertDatabaseCount('cv_ai_turns', 0);
});

it('saves ordered workflows and reports rejected, skipped and accepted attempts with their agents', function () {
    $admin = User::factory()->create()->assignRole(WorkspaceRole::Administrator->value);
    $collection = DB::table('cv_knowledge_collections')->insertGetId(['name' => 'POS', 'enabled' => true]);
    $channel = DB::table('cv_channel_accounts')->insertGetId(['name' => 'Fixture', 'external_id' => '572', 'type' => 'whatsapp', 'enabled' => true]);
    $entry = DB::table('cv_knowledge_entries')->insertGetId([
        'collection_id' => $collection, 'title' => 'Kitchen display', 'kind' => 'faq', 'language' => 'en',
        'body' => 'Check that the kitchen display is open for the right branch.', 'enabled' => true, 'public' => true,
    ]);
    config([
        'converse.ai.agents.specialist' => ['name' => 'Specialist', 'instructions' => 'Follow the support guide.', 'collections' => [$collection]],
        'converse.ai.agents.other' => ['name' => 'Other team', 'collections' => []],
        'converse.ai.channels' => [$channel => ['collections' => [$collection]]],
    ]);
    $workflow = [
        'name' => 'Complex WhatsApp support',
        'when' => ['agent' => 'support', 'channel' => 'whatsapp', 'language' => 'en', 'intent' => 'support', 'complexity' => 'complex'],
        'steps' => [
            ['provider' => 'ollama', 'model' => 'first-model'],
            ['provider' => 'openrouter', 'model' => 'vendor/model:free'],
            ['provider' => 'ollama', 'model' => 'other-model', 'agent' => 'other'],
            ['provider' => 'ollama', 'model' => 'specialist-model', 'agent' => 'specialist'],
        ],
    ];
    $this->actingAs(User::factory()->create()->assignRole('staff'))
        ->patch(route('settings.converse.workflows'), ['workflows' => [$workflow]])->assertForbidden();
    $this->actingAs($admin)->patch(route('settings.converse.workflows'), ['workflows' => [$workflow]])->assertRedirect(route('settings.index'));
    $this->get(route('settings.index'))->assertInertia(fn ($page) => $page
        ->where('converseAi.providerSettings.workflows.0.steps.1.model', 'vendor/model:free'));

    Http::fake(['http://127.0.0.1:11434/api/chat' => Http::sequence()
        ->push(['done' => true, 'done_reason' => 'stop', 'message' => ['content' => '{"source_ids":[99999],"confidence":0.99}']])
        ->push(['done' => true, 'done_reason' => 'stop', 'message' => ['content' => json_encode(['source_ids' => [$entry], 'confidence' => 0.99])]])]);

    $this->postJson(route('converse.playground.run'), [
        'mode' => 'knowledge', 'language' => 'en', 'message' => 'Can you help with a kitchen display error?', 'channel' => $channel,
    ])->assertOk()
        ->assertJsonPath('attempts.0.status', 'rejected')
        ->assertJsonPath('attempts.1.reason', 'provider')
        ->assertJsonPath('attempts.2.reason', 'agent')
        ->assertJsonPath('attempts.3.agent', 'specialist')
        ->assertJsonPath('attempts.3.status', 'accepted')
        ->assertJsonPath('draft.model', 'specialist-model')
        ->assertJsonPath('draft.fallback', true);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request['model'] === 'specialist-model'
        && str_contains($request['messages'][0]['content'], 'Follow the support guide.'));

    $this->patch(route('settings.converse.routing'), [
        'routing' => json_encode(['agents' => config('converse.ai.agents'), 'rules' => []]),
    ])->assertRedirect();
    expect(app(ConverseAiSettings::class)->public()['workflows'][0]['name'])->toBe('Complex WhatsApp support');
});

it('exposes the development runbook only to authorized channel administrators', function () {
    $route = route('converse.development');
    $this->actingAs(User::factory()->create()->assignRole('staff'))->get($route)->assertForbidden();
    $this->actingAs(User::factory()->create()->assignRole('administrator'))->get($route)
        ->assertOk()->assertInertia(fn ($page) => $page->component('Converse/Development')
        ->where('runbook', fn (string $html) => str_contains($html, 'converse-knowledge')
            && str_contains($html, '/api/v1/converse/webhooks/whatsapp')
            && str_contains($html, 'Phone Number ID')
            && ! str_contains($html, 'fixture-openai-token')));
});
