<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('retains existing demo users but offers no way to sign in as one', function () {
    $this->app->detectEnvironment(fn () => 'local');
    config(['app.debug' => true, 'erp.demo_login' => true]);
    $demo = User::factory()->create(['is_demo' => true]);

    $this->withoutVite()->get(route('login'))->assertInertia(fn (Assert $page) => $page
        ->component('Login')->missing('demoUsers')->has('localQuickSignInUsers', 0));
    $this->withSession(['_token' => 'test-token'])
        ->post('/login/demo/'.$demo->id, ['_token' => 'test-token'])->assertMethodNotAllowed();
    $this->post(route('login.local'), ['user_id' => $demo->id, '_token' => 'test-token'])->assertNotFound();
    $this->post(route('login.store'), ['email' => $demo->email, 'password' => 'password', '_token' => 'test-token'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();
    $this->assertDatabaseHas('users', ['id' => $demo->id, 'is_demo' => true]);
});
