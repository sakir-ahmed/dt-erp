<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('shows seeded demo accounts and signs in to the dashboard locally', function () {
    $this->app->detectEnvironment(fn () => 'local');
    config(['erp.demo_login' => true]);
    $this->seed(DemoUserSeeder::class);
    $this->seed(DemoUserSeeder::class);

    $this->withoutVite()->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page->has('demoUsers', 2));

    $demo = User::query()->where('is_demo', true)->firstOrFail();
    expect($demo->hasRole(WorkspaceRole::Administrator->value))->toBeTrue();

    $this->withSession(['_token' => 'test-demo-token'])->post(route('login.demo', $demo), ['_token' => 'test-demo-token'])->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($demo);
});

it('rejects ordinary accounts and disables demo sign-in outside local development', function () {
    $this->app->detectEnvironment(fn () => 'local');
    config(['erp.demo_login' => true]);
    $this->seed(DemoUserSeeder::class);
    $regular = User::factory()->create();
    $demo = User::query()->where('is_demo', true)->firstOrFail();

    $this->withSession(['_token' => 'test-demo-token'])->post(route('login.demo', $regular), ['_token' => 'test-demo-token'])->assertNotFound();

    $this->app->detectEnvironment(fn () => 'production');
    $this->withSession(['_token' => 'test-demo-token'])->post(route('login.demo', $demo), ['_token' => 'test-demo-token'])->assertNotFound();
    $this->assertGuest();
});
