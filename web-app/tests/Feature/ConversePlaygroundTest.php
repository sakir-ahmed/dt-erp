<?php

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
        'converse.ai.openai_key' => 'fixture-openai-token',
        'converse.ai.allow_cloud' => true,
    ]);
    Http::preventStrayRequests();
});

it('routes only synthetic content, falls back to OpenAI when permitted and returns routing provenance', function () {
    Http::fake([
        'http://127.0.0.1:11434/api/chat' => Http::response([], 503),
        'https://api.openai.com/v1/responses' => Http::response([
            'status' => 'completed',
            'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Thanks for reaching out.']]]],
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

it('can disable and re-enable the local provider without disabling configured cloud tests', function () {
    $admin = User::factory()->create()->assignRole(WorkspaceRole::Administrator->value);
    $form = ['primary' => 'ollama', 'allow_cloud' => true, 'ollama_port' => 11434,
        'ollama_model' => 'fixture:local', 'remove_openai_key' => false];
    $this->actingAs($admin)->patch(route('converse.playground.providers'), [...$form, 'ollama_enabled' => false])->assertRedirect();
    $this->get(route('converse.playground.index'))->assertInertia(fn ($page) => $page->where('providerSettings.ollama_enabled', false));
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

    $ciphertext = DB::table('cv_ai_settings')->where('id', 1)->value('openai_key');
    expect($ciphertext)->not->toBe($testKey);
    expect(Crypt::decryptString($ciphertext))->toBe($testKey);
    expect(DB::table('cv_audit_events')->where('action', 'ai.providers_saved')->soleValue('changes'))->not->toContain($testKey);
    $this->get(route('converse.playground.index'))->assertDontSee($testKey)->assertDontSee($ciphertext);

    $this->patch($url, [...$settings, 'openai_key' => '', 'remove_openai_key' => true])->assertRedirect();
    $this->assertDatabaseHas('cv_ai_settings', ['openai_key' => null, 'openai_key_removed' => true]);
    $this->get(route('converse.playground.index'))->assertInertia(
        fn ($page) => $page->where('providerSettings.has_openai_key', false)->missing('providerSettings.openai_key'),
    );
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
