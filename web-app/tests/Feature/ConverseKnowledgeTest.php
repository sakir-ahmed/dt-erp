<?php

use App\Actions\RetrieveConverseKnowledge;
use App\Enums\WorkspaceRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite()->seed(DatabaseSeeder::class);
});

it('manages structured knowledge with explicit publication, scoped prices and audit records', function () {
    $admin = User::factory()->create()->assignRole(WorkspaceRole::Administrator->value);
    $staff = User::factory()->create()->assignRole(WorkspaceRole::Staff->value);
    $this->actingAs($staff)->get(route('converse.knowledge'))->assertForbidden();
    $this->actingAs($admin)->post(route('converse.knowledge.collections.store'), ['name' => 'ERP catalog', 'enabled' => true])->assertRedirect();
    $collection = (int) DB::table('cv_knowledge_collections')->soleValue('id');
    $url = route('converse.knowledge.entries.store', $collection);
    $public = ['language' => 'en', 'enabled' => true, 'public' => true];
    $this->post($url, [...$public, 'kind' => 'product', 'title' => 'DT ERP product'])->assertRedirect();
    $subject = (int) DB::table('cv_knowledge_entries')->soleValue('id');

    $price = [...$public, 'kind' => 'price', 'title' => 'Monthly plan', 'subject_id' => $subject,
        'amount_type' => 'monthly', 'amount' => '125.50', 'currency' => 'BDT'];
    $this->post($url, $price)->assertRedirect();
    $this->post($url, [...$price, 'title' => 'Invalid subject', 'subject_id' => $subject + 999])->assertSessionHasErrors('subject_id');
    $this->post($url, [...$price, 'title' => 'Expired plan', 'valid_to' => now('Asia/Dhaka')->subDay()->toDateString()])->assertRedirect();
    $this->post($url, [...$price, 'title' => 'Internal plan', 'public' => false])->assertRedirect();

    $retrieve = app(RetrieveConverseKnowledge::class);
    expect($retrieve([], 'DT ERP'))->toBeEmpty();
    expect($retrieve([$collection], 'DT ERP', demo: true))->toBeEmpty();
    $available = $retrieve([$collection], 'DT ERP');
    expect($available->pluck('title')->all())->toBe(['Monthly plan', 'DT ERP product']);
    expect((float) $available->first()->amount)->toBe(125.5);
    expect($available->first()->currency)->toBe('BDT');

    $this->get(route('converse.knowledge', ['collection' => $collection]))->assertInertia(fn (Assert $page) => $page
        ->component('Converse/Knowledge')->has('entries.data', 4));
    $this->patch(route('converse.knowledge.entries.update', [$collection, $subject]),
        [...$public, 'kind' => 'product', 'title' => 'DT ERP product', 'enabled' => false])->assertRedirect();
    expect($retrieve([$collection], 'DT ERP'))->toBeEmpty();
    $this->assertDatabaseHas('cv_audit_events', ['action' => 'knowledge.entry_saved', 'subject_id' => $subject, 'user_id' => $admin->id]);
});
