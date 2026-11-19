<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('redirects guests to the sign-in page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->withoutVite()->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page->component('Login'));
});

it('signs in with valid credentials and signs out while retaining the language', function () {
    $user = User::factory()->create();

    $this->withSession(['locale' => 'bn'])
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);

    $this->post(route('logout'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('locale', 'bn');

    $this->assertGuest();
});

it('rejects invalid credentials', function () {
    $user = User::factory()->create();

    $this->from(route('login'))
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});
