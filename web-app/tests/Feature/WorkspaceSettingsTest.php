<?php

use App\Actions\AiProviderCredentials;
use App\Actions\RecordConverseInboundMessage;
use App\Actions\WorkspaceSettings;
use App\Jobs\ReplyToConverseTurn;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite()->seed(DatabaseSeeder::class);
    config(['converse.outbound_enabled' => true, 'converse.ai.auto_reply' => true]);
});

function settingsChannel(string $type): int
{
    return DB::table('cv_channel_accounts')->insertGetId([
        'type' => $type, 'name' => "Settings fixture {$type}", 'external_id' => '501',
        'send_token' => Crypt::encryptString('fixture-channel-token'),
    ]);
}

function settingsInbound(int $account): int
{
    $message = app(RecordConverseInboundMessage::class)($account, '8801000000301', 'in-settings', 'Hello there', now()->subSecond());

    return (int) $message;
}

it('shows settings to administrators only', function () {
    $this->actingAs(User::factory()->create())->get('/settings')->assertForbidden();
    $this->actingAs(User::factory()->create()->assignRole('staff'))->get('/settings')->assertForbidden();
    $this->actingAs(User::factory()->create()->assignRole('administrator'))
        ->get('/settings')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Settings/Index')
        ->where('converse.auto_reply', true)
        ->where('converse.new_conversation_mode', 'ai')
        ->where('converse.channel_types.messenger', true)
        ->where('converse.channel_types.whatsapp', true));
});

it('starts new conversations in AI mode by default on both channels', function () {
    foreach (['messenger', 'whatsapp'] as $type) {
        $account = settingsChannel($type);
        settingsInbound($account);

        $conversation = DB::table('cv_conversations')->join('cv_contact_identities', 'cv_contact_identities.id', '=', 'cv_conversations.contact_identity_id')
            ->where('cv_contact_identities.channel_account_id', $account)->first(['cv_conversations.mode']);
        expect($conversation->mode)->toBe('ai');
    }
});

it('gates automatic replies per channel type and lets playground channels override', function () {
    app(WorkspaceSettings::class)->put('converse', [
        'auto_reply' => true,
        'new_conversation_mode' => 'ai',
        'channel_types' => ['messenger' => false, 'whatsapp' => true],
    ]);
    $messenger = settingsChannel('messenger');
    $message = settingsInbound($messenger);

    Http::fake([
        'http://127.0.0.1:11434/api/chat' => Http::response(status: 500),
        'https://graph.facebook.com/*' => Http::response(['message_id' => 'mid.settings']),
    ]);
    app()->call([new ReplyToConverseTurn($message), 'handle']);
    Http::assertNothingSent();
    $this->assertDatabaseCount('cv_messages', 1);

    // A channel configured in the AI playground overrides its type default.
    config(['converse.ai.channels' => [$messenger => ['enabled' => true]]]);
    app()->call([new ReplyToConverseTurn($message), 'handle']);
    $this->assertDatabaseHas('cv_messages', ['direction' => 'out', 'sender_type' => 'ai', 'external_id' => 'mid.settings']);
});

it('stops every automatic reply when the master switch is off', function () {
    app(WorkspaceSettings::class)->put('converse', [
        'auto_reply' => false,
        'new_conversation_mode' => 'human',
        'channel_types' => ['messenger' => true, 'whatsapp' => true],
    ]);
    $account = settingsChannel('whatsapp');
    $message = settingsInbound($account);

    Http::fake();
    app()->call([new ReplyToConverseTurn($message), 'handle']);
    Http::assertNothingSent();
    $this->assertDatabaseCount('cv_messages', 1);
    expect(DB::table('cv_conversations')->where('contact_identity_id', DB::table('cv_contact_identities')
        ->where('channel_account_id', $account)->value('id'))->value('mode'))->toBe('human');
});

it('persists settings through the admin form and audits the change', function () {
    $admin = User::factory()->create()->assignRole('administrator');
    $this->actingAs($admin);

    $this->patch('/settings/converse', [
        'auto_reply' => true, 'new_conversation_mode' => 'human',
        'channel_types' => ['messenger' => true, 'whatsapp' => false],
    ])->assertRedirect();

    $stored = DB::table('settings')->where('section', 'converse')->pluck('value', 'key');
    expect($stored)->toHaveCount(3)
        ->and(json_decode($stored['channel_types'], true))->toBe(['messenger' => true, 'whatsapp' => false]);
    $this->assertDatabaseHas('cv_audit_events', ['user_id' => $admin->id, 'action' => 'settings.updated']);

    $this->patch('/settings/application', ['default_locale' => 'bn'])->assertRedirect();
    $this->get('/settings')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('application.default_locale', 'bn'));
});

it('manages encrypted AI provider keys in common settings without exposing them', function () {
    $route = route('settings.ai-providers.update');
    $admin = User::factory()->create()->assignRole('administrator');
    $demo = User::factory()->create(['is_demo' => true])->assignRole('administrator');
    $keys = [
        'openrouter_key' => 'fixture-router-secret', 'openai_key' => 'fixture-openai-secret',
        'deepseek_key' => 'fixture-deepseek-secret', 'anthropic_key' => 'fixture-anthropic-secret',
    ];
    $this->actingAs(User::factory()->create()->assignRole('staff'))->patch($route, $keys)->assertForbidden();
    $this->actingAs($demo)->patch($route, $keys)->assertForbidden();
    $this->get(route('settings.index'))->assertInertia(fn (AssertableInertia $page) => $page->has('aiProviders', 0));

    $this->actingAs($admin)->from(route('settings.index'))->patch($route, $keys)->assertRedirect(route('settings.index'));
    $rows = DB::table('settings')->where('section', 'ai_provider_credentials')->pluck('value', 'key');
    expect($rows)->toHaveCount(4);
    foreach ($rows as $provider => $value) {
        expect($value)->not->toContain($keys[$provider.'_key'])
            ->and(Crypt::decryptString(json_decode($value, true)))->toBe($keys[$provider.'_key']);
    }
    $audit = DB::table('cv_audit_events')->where('action', 'settings.ai_provider_keys_updated')->soleValue('changes');
    $page = $this->get(route('settings.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('aiProviders.0.configured', true)->missing('aiProviders.0.key'));
    foreach ($keys as $key) {
        expect($audit)->not->toContain($key);
        $page->assertDontSee($key);
    }

    $this->patch($route, ['openrouter_key' => 'fixture-replacement', 'remove_openrouter_key' => true])
        ->assertSessionHasErrors('openrouter_key')->assertSessionMissing('_old_input.openrouter_key');
    $this->patch($route, ['openrouter_key' => 'fixture-replacement', 'remove_deepseek_key' => true])->assertRedirect();
    $saved = app(AiProviderCredentials::class)->get();
    expect($saved['openrouter'])->toBe('fixture-replacement')
        ->and($saved['openai'])->toBe($keys['openai_key'])
        ->and($saved)->not->toHaveKey('deepseek');
});

it('migrates an existing encrypted OpenAI key into common settings', function () {
    $stored = Crypt::encryptString('fixture-legacy-key');
    DB::table('cv_ai_settings')->where('id', 1)->update(['openai_key' => $stored]);
    $migration = require database_path('migrations/2026_11_21_193000_move_ai_provider_keys_to_workspace_settings.php');
    $migration->up();

    expect(app(AiProviderCredentials::class)->get())->toBe(['openai' => 'fixture-legacy-key']);
    $this->assertDatabaseHas('cv_ai_settings', ['id' => 1, 'openai_key' => null]);

    $migration->down();
    $this->assertDatabaseHas('cv_ai_settings', ['id' => 1, 'openai_key' => $stored]);
});

it('uses the stored default locale for fresh sessions', function () {
    app(WorkspaceSettings::class)->put('application', ['default_locale' => 'bn']);

    $this->get(route('login'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('locale', 'bn'));
});

it('rejects invalid settings payloads', function () {
    $this->actingAs(User::factory()->create()->assignRole('administrator'));

    $this->patch('/settings/converse', [
        'auto_reply' => 'yes', 'new_conversation_mode' => 'paused',
        'channel_types' => ['messenger' => true, 'whatsapp' => true],
    ])->assertSessionHasErrors(['auto_reply', 'new_conversation_mode']);
    $this->patch('/settings/application', ['default_locale' => 'fr'])->assertSessionHasErrors(['default_locale']);
    Http::assertNothingSent();
});
