<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
});

it('renders the workspace', function () {
    $this->actingAs(User::factory()->create());
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('locale', 'en')
            ->where('labels.dashboard', 'Dashboard'));
});

it('persists the chosen language and serves translated labels', function () {
    $this->actingAs(User::factory()->create());
    $this->post(route('locale.update'), ['locale' => 'bn'])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('locale', 'bn');

    $this->get(route('dashboard'))
        ->assertSee('lang="bn"', false)
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('locale', 'bn')
            ->where('labels.dashboard', 'ড্যাশবোর্ড'));
});

it('rejects unsupported languages', function () {
    $this->withSession(['locale' => 'bn'])
        ->post(route('locale.update'), ['locale' => 'fr'])
        ->assertSessionHasErrors('locale')
        ->assertSessionHas('locale', 'bn');
});
