<?php

use App\Actions\ComposeConverseReply;
use App\Actions\RecordConverseInboundMessage;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('preserves customer preferences and extracts bounded durable memory even when auto-reply is off', function () {
    config(['converse.ai.auto_reply' => false]);
    $account = DB::table('cv_channel_accounts')->insertGetId(['name' => 'Fixture', 'type' => 'messenger', 'external_id' => '123']);
    $record = app(RecordConverseInboundMessage::class);
    $record($account, 'sender', 'needs', 'I need ERP software for a shop', now()->subDays(2));
    $record($account, 'sender', 'language', 'English e bolen', now()->subDay());
    $record($account, 'sender', 'older-language', 'Reply in Bangla', now()->subDays(3));
    $record($account, 'sender', 'objection', 'There is a problem with inventory', now());
    $record($account, 'sender', 'objection', 'Duplicate should not replace the summary', now());

    expect(DB::table('cv_contacts')->soleValue('language_preference'))->toBe('en');
    $memory = json_decode(DB::table('cv_conversations')->soleValue('memory'), true);
    expect($memory['needs']['text'])->toBe('I need ERP software for a shop')
        ->and($memory['objection']['text'])->toBe('There is a problem with inventory');
});

it('authorizes and audits agent setup, then uses the first matching route and language override', function () {
    $this->seed(DatabaseSeeder::class);
    config(['inertia.ssr.enabled' => false, 'converse.ai.ollama_model' => 'default', 'converse.ai.embedding_model' => null]);
    Http::preventStrayRequests();
    $collection = DB::table('cv_knowledge_collections')->insertGetId(['name' => 'Fixture', 'enabled' => true]);
    $entry = DB::table('cv_knowledge_entries')->insertGetId([
        'collection_id' => $collection, 'kind' => 'faq', 'language' => 'en', 'title' => 'ERP',
        'body' => 'Approved ERP answer.', 'enabled' => true, 'public' => true,
    ]);
    $payload = ['routing' => json_encode([
        'agents' => ['sales' => ['name' => 'Sales', 'language' => 'en', 'model' => 'agent-default', 'collections' => [$collection]]],
        'rules' => [
            ['when' => ['agent' => 'sales', 'language' => 'en', 'channel' => 'messenger'], 'model' => 'first-match'],
            ['when' => [], 'model' => 'second-match'],
        ],
    ])];
    $url = route('converse.playground.routing');
    $this->actingAs(User::factory()->create()->assignRole('staff'))->patch($url, $payload)->assertForbidden();
    $this->actingAs(User::factory()->create()->assignRole('administrator'))->patch($url, $payload)->assertRedirect();
    $this->assertDatabaseHas('cv_audit_events', ['action' => 'ai.routing_saved']);
    Http::fake(['http://127.0.0.1:11434/*' => Http::response([
        'done' => true, 'done_reason' => 'stop',
        'message' => ['content' => json_encode(['source_id' => $entry, 'confidence' => 0.99])],
    ])]);
    $result = app(ComposeConverseReply::class)(collect([(object) ['direction' => 'in', 'body' => 'ERP সফটওয়্যার কীভাবে কাজ করে?']]), [
        'channel' => 'messenger', 'collections' => [$collection], 'memory' => ['needs' => ['text' => 'ERP']],
    ]);
    expect($result['body'])->toBe('Approved ERP answer.')->and($result['language'])->toBe('en');
    Http::assertSent(fn (Request $request) => $request['model'] === 'first-match'
        && str_contains($request['messages'][1]['content'], '"memory":{"needs":{"text":"ERP"}}'));
});
