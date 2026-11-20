<?php

use App\Actions\LocalQuickSignIn;
use App\Enums\WorkspacePermission;
use App\Enums\WorkspaceRole;
use App\Models\User;
use Database\Seeders\LocalQuickSignInSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->detectEnvironment(fn () => 'local');
    config(['app.debug' => true, 'app.url' => 'http://localhost', 'erp.demo_login' => false]);
});

it('refreshes only proven fixtures and can be rerun', function () {
    $collidingUser = User::factory()->create(['email' => LocalQuickSignIn::email(WorkspaceRole::Administrator)]);
    $original = $collidingUser->fresh()->getRawOriginal();
    $currentKey = config('app.key');
    config(['app.key' => 'previous-fixture-test-key']);
    $this->seed(LocalQuickSignInSeeder::class);
    config(['app.key' => $currentKey]);

    expect(LocalQuickSignIn::eligibleAccounts())->toHaveCount(0);

    $this->artisan('erp:test-accounts:seed --refresh')
        ->expectsQuestion('Previous APP_KEY used to seed the fixtures', 'wrong-key')
        ->assertFailed();

    $this->artisan('erp:test-accounts:seed --refresh')
        ->expectsQuestion('Previous APP_KEY used to seed the fixtures', 'previous-fixture-test-key')
        ->assertSuccessful();

    $staff = LocalQuickSignIn::eligibleAccounts()->sole();

    $this->artisan('erp:test-accounts:seed --refresh')
        ->expectsQuestion('Previous APP_KEY used to seed the fixtures', 'previous-fixture-test-key')
        ->assertSuccessful();

    expect($staff->email)->toBe(LocalQuickSignIn::email(WorkspaceRole::Staff))
        ->and($staff->fresh()->getRawOriginal())->toBe($staff->getRawOriginal())
        ->and($collidingUser->fresh()->getRawOriginal())->toBe($original);
});

it('blocks fixture seeding on a public app URL even with local debug enabled', function () {
    config(['app.url' => 'https://erp.example.com']);

    $this->artisan('erp:test-accounts:seed')->assertFailed();
    $this->seed(LocalQuickSignInSeeder::class);

    $this->assertDatabaseEmpty('users');
});

it('blocks listing and quick sign-in for a public request to a local configuration', function () {
    $this->artisan('erp:test-accounts:seed')->expectsOutput('Eligible local test accounts: 2.')->assertSuccessful();
    $staff = LocalQuickSignIn::eligibleAccounts()->last();

    $this->withoutVite()->get('https://erp.example.com/login')
        ->assertInertia(fn (Assert $page) => $page->has('localQuickSignInUsers', 0));

    $this->withSession(['_token' => 'local-sign-in-test'])
        ->post('https://erp.example.com/login/local', ['user_id' => $staff->id, '_token' => 'local-sign-in-test'])
        ->assertNotFound();

    $this->assertGuest();
});

it('shows the seeded accounts and signs staff into the non-demo inbox', function () {
    $this->seed(LocalQuickSignInSeeder::class);

    $staff = User::query()->where('email', 'local-staff@erp.example.invalid')->firstOrFail();
    expect($staff->hasRole(WorkspaceRole::Staff->value))->toBeTrue()
        ->and($staff->can(WorkspacePermission::ViewConverseInbox->value))->toBeTrue()
        ->and($staff->is_demo)->toBeFalse();

    $this->withoutVite()->get(route('login'))->assertInertia(fn (Assert $page) => $page
        ->component('Login')
        ->has('localQuickSignInUsers', 2)
        ->where('localQuickSignInUsers.1.id', $staff->id)
        ->missing('localQuickSignInUsers.1.email')
        ->missing('demoUsers'));

    $this->withSession(['_token' => 'local-sign-in-test'])
        ->post(route('login.local'), ['user_id' => $staff->id, '_token' => 'local-sign-in-test'])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('_token', fn (string $token) => $token !== 'local-sign-in-test');

    $this->assertAuthenticatedAs($staff);
    $this->get(route('converse.inbox'))->assertOk();
});

it('requires both local environment and debug mode for listing, login, and seeding', function (string $environment, bool $debug) {
    $this->seed(LocalQuickSignInSeeder::class);
    $administrator = User::query()->where('email', 'local-administrator@erp.example.invalid')->firstOrFail();
    User::query()->where('email', 'local-staff@erp.example.invalid')->delete();

    $this->app->detectEnvironment(fn () => $environment);
    config(['app.debug' => $debug]);
    $this->seed(LocalQuickSignInSeeder::class);

    $this->assertDatabaseMissing('users', ['email' => 'local-staff@erp.example.invalid']);
    $this->withoutVite()->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page->has('localQuickSignInUsers', 0));

    $this->withSession(['_token' => 'local-sign-in-test'])
        ->post(route('login.local'), ['user_id' => $administrator->id, '_token' => 'local-sign-in-test'])
        ->assertNotFound();

    $this->assertGuest();
})->with([
    'local with debug disabled' => ['local', false],
    'non-local with debug enabled' => ['production', true],
]);

it('leaves existing users intact and denies unseeded and demo IDs', function () {
    $existing = User::factory()->create(['email' => 'local-administrator@erp.example.invalid']);
    $demo = User::factory()->create(['is_demo' => true]);
    $original = $existing->fresh()->getAttributes();

    $this->seed(LocalQuickSignInSeeder::class);
    $staff = User::query()->where('email', 'local-staff@erp.example.invalid')->firstOrFail();
    $this->seed(LocalQuickSignInSeeder::class);

    expect($existing->fresh()->getAttributes())->toBe($original)
        ->and($existing->getRoleNames()->all())->toBe([])
        ->and($staff->fresh()->getAttributes())->toBe($staff->getAttributes());

    $this->withoutVite()->get(route('login'))->assertInertia(fn (Assert $page) => $page
        ->has('localQuickSignInUsers', 1)
        ->where('localQuickSignInUsers.0.id', $staff->id));

    foreach ([$existing->id, $demo->id, $staff->id + 1] as $id) {
        $this->withSession(['_token' => 'local-sign-in-test'])
            ->post(route('login.local'), ['user_id' => $id, '_token' => 'local-sign-in-test'])
            ->assertNotFound();
    }

    $this->assertGuest();
});

it('rejects a quick sign-in post without a CSRF token', function () {
    $this->seed(LocalQuickSignInSeeder::class);
    $staff = User::query()->where('email', 'local-staff@erp.example.invalid')->firstOrFail();

    $this->post(route('login.local'), ['user_id' => $staff->id])->assertStatus(419);
    $this->assertGuest();
});

it('lists and authenticates only verified fixtures on the demo route', function () {
    $this->seed(LocalQuickSignInSeeder::class);
    $staff = LocalQuickSignIn::eligibleAccounts()->last();
    $unrelated = User::factory()->create();
    config(['app.debug' => false, 'erp.demo_login' => true]);
    $origin = 'https://erp.example.com';

    $this->withoutVite()->get($origin.'/login')->assertInertia(fn (Assert $page) => $page
        ->where('quickSignInPath', '/login/demo')->has('localQuickSignInUsers', 2));

    $this->withSession(['_token' => 'demo-token'])
        ->post($origin.'/login/demo', ['user_id' => $unrelated->id, '_token' => 'demo-token'])
        ->assertNotFound();
    $this->assertGuest();

    $this->post($origin.'/login/demo', ['user_id' => $staff->id, '_token' => 'demo-token'])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('_token', fn (string $token) => $token !== 'demo-token');
    $this->assertAuthenticatedAs($staff);
});

it('blocks demo login when disabled or in production', function (bool $enabled, string $environment) {
    $this->seed(LocalQuickSignInSeeder::class);
    $staff = LocalQuickSignIn::eligibleAccounts()->last();
    $this->app->detectEnvironment(fn () => $environment);
    config(['erp.demo_login' => $enabled]);
    $origin = 'https://erp.example.com';

    $this->withoutVite()->get($origin.'/login')->assertInertia(fn (Assert $page) => $page
        ->has('localQuickSignInUsers', 0));
    $this->withSession(['_token' => 'demo-token'])
        ->post($origin.'/login/demo', ['user_id' => $staff->id, '_token' => 'demo-token'])
        ->assertNotFound();
    $this->assertGuest();
})->with([
    'disabled' => [false, 'local'],
    'production' => [true, 'production'],
]);
