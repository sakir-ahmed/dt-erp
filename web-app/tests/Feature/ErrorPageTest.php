<?php

use Inertia\Testing\AssertableInertia as Assert;

it('shows the translated error page for an unknown web address', function () {
    $this->withoutVite()->withSession(['locale' => 'bn'])->get('/unknown-page')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Error')
            ->where('status', 404)
            ->where('locale', 'bn')
            ->where('returnUrl', route('login')));
});

it('returns JSON for an unknown API address', function () {
    $this->getJson('/api/v1/unknown-page')->assertNotFound()->assertJsonStructure(['message']);
});
