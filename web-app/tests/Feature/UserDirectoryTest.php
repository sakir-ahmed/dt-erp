<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    $this->withoutVite();
});

it('lets administrators view the user directory', function () {
    $administrator = User::factory()->create()->assignRole(WorkspaceRole::Administrator->value);

    $this->actingAs($administrator)->get(route('users.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Users/Index')
            ->where('canViewUsers', true)
            ->has('users.data', 1)
            ->where('users.data.0.email', $administrator->email)
            ->missing('users.data.0.password'));
});

it('denies staff access and hides the directory from navigation', function () {
    $staff = User::factory()->create()->assignRole(WorkspaceRole::Staff->value);

    $this->actingAs($staff)->get(route('users.index'))
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Error')
            ->where('status', 403)
            ->where('signedIn', true));

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('canViewUsers', false));
});

it('creates and updates staff accounts as an administrator', function () {
    $administrator = User::factory()->create()->assignRole(WorkspaceRole::Administrator->value);
    $this->actingAs($administrator)
        ->post(route('users.store'), [
            'name' => 'Example Staff',
            'email' => 'example-staff@example.com',
            'password' => 'a staff test password',
        ])->assertRedirect(route('users.index'));

    $staff = User::query()->where('email', 'example-staff@example.com')->firstOrFail();
    expect($staff->hasRole(WorkspaceRole::Staff->value))->toBeTrue();
    $passwordHash = $staff->password;

    $this->patch(route('users.update', $staff), [
        'name' => 'Updated Staff',
        'email' => $staff->email,
        'password' => '',
    ])->assertRedirect(route('users.index'));

    expect($staff->refresh()->password)->toBe($passwordHash);
    $this->get(route('users.index', ['search' => 'Updated Staff']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.name', 'Updated Staff'));
});

it('prevents demo administrators from managing regular users', function () {
    $regular = User::factory()->create();
    $demo = User::factory()->create(['is_demo' => true])->assignRole(WorkspaceRole::Administrator->value);

    $this->actingAs($demo)->get(route('users.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canManageUsers', false)
            ->has('users.data', 1)
            ->where('users.data.0.id', $demo->id));

    $this->patch(route('users.update', $regular), [])->assertForbidden();
    $this->post(route('users.store'), [])->assertForbidden();
});
