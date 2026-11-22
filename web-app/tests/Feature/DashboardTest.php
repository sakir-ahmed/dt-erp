<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            ->where('labels.dashboard', 'Dashboard')
            ->where('converse', null)->where('aiUsage', null)->where('knowledge', null));
});

function dashboardThread(int $account, string $name, CarbonImmutable $at, string $mode = 'human', string $status = 'open'): int
{
    $contact = DB::table('cv_contacts')->insertGetId(['name' => $name]);
    $identity = DB::table('cv_contact_identities')->insertGetId([
        'contact_id' => $contact, 'channel_account_id' => $account, 'external_id' => $name,
    ]);

    return DB::table('cv_conversations')->insertGetId([
        'contact_identity_id' => $identity, 'mode' => $mode, 'status' => $status,
        'created_at' => $at, 'last_message_at' => $at,
    ]);
}

it('summarizes actionable, Dhaka-timed data without crossing inbox or settings permissions', function () {
    $this->seed(DatabaseSeeder::class);
    $admin = User::factory()->create()->assignRole('administrator');
    $staff = User::factory()->create()->assignRole('staff');
    $demo = User::factory()->create(['is_demo' => true])->assignRole('administrator');
    $this->travelTo(CarbonImmutable::parse('2026-11-22 01:30', 'Asia/Dhaka'));
    $today = CarbonImmutable::now('Asia/Dhaka')->startOfDay()->utc();
    $wa = DB::table('cv_channel_accounts')->insertGetId(['type' => 'whatsapp', 'name' => 'Live WA', 'external_id' => 'wa']);
    $messenger = DB::table('cv_channel_accounts')->insertGetId(['type' => 'messenger', 'name' => 'Live FB', 'external_id' => 'fb']);
    $demoChannel = DB::table('cv_channel_accounts')->insertGetId(['type' => 'whatsapp', 'name' => 'Demo WA', 'external_id' => 'wa', 'is_demo' => true]);
    $waiting = dashboardThread($wa, 'Waiting customer', $today->addMinute());
    $answered = dashboardThread($messenger, 'Answered customer', $today->subMinute(), 'ai');
    $closed = dashboardThread($wa, 'Closed customer', $today->subMinute(), status: 'resolved');
    $demoThread = dashboardThread($demoChannel, 'Fictional customer', $today->addMinute());
    foreach ([
        [$waiting, 'in', 'customer', null, $today->addMinute()],
        [$waiting, 'out', 'staff', 'failed', $today->addMinutes(2)],
        [$answered, 'in', 'customer', null, $today->subMinute()],
        [$answered, 'out', 'ai', 'delivered', $today->addMinutes(2)],
        [$answered, 'out', 'ai', 'failed', $today->addMinutes(3)],
        [$demoThread, 'in', 'customer', null, $today->addMinutes(4)],
    ] as [$thread, $direction, $sender, $delivery, $at]) {
        DB::table('cv_messages')->insert([
            'conversation_id' => $thread, 'direction' => $direction, 'sender_type' => $sender, 'delivery_status' => $delivery,
            'body' => 'Fixture message body', 'occurred_at' => $at,
        ]);
    }
    $tag = DB::table('cv_conversation_tags')->insertGetId(['name' => 'Demo', 'created_by' => $admin->id]);
    foreach ([$waiting, $closed] as $thread) {
        DB::table('cv_conversation_tag')->insert(['conversation_id' => $thread, 'tag_id' => $tag]);
    }
    foreach ([
        ['local', 'accepted', 30, $today->addMinute()],
        ['paid', 'unavailable', null, $today->addMinutes(2)],
        ['paid', 'accepted', 100, $today->subDays(7)],
    ] as [$billing, $status, $tokens, $at]) {
        DB::table('cv_ai_calls')->insert([
            'provider' => $billing === 'local' ? 'ollama' : 'openai', 'model' => 'fixture',
            'operation' => 'chat', 'origin' => 'reply', 'billing' => $billing, 'status' => $status,
            'total_tokens' => $tokens, 'duration_ms' => 100, 'detail' => 'Fixture prompt', 'called_at' => $at,
        ]);
    }
    $collection = DB::table('cv_knowledge_collections')->insertGetId(['name' => 'Fixture knowledge', 'enabled' => true]);
    DB::table('cv_knowledge_documents')->insert([
        'collection_id' => $collection, 'owner_id' => $admin->id, 'name' => 'fixture.md',
        'path' => 'fixture.md', 'format' => 'md', 'language' => 'en', 'status' => 'failed',
    ]);

    $this->actingAs($admin)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('converse.new_today', 1)->where('converse.waiting_count', 1)
            ->where('converse.waiting.0.id', $waiting)->where('converse.open_demo_inquiries', 1)
            ->where('converse.ai_replies_today', 1)->has('converse.activity', 7)
            ->where('converse.activity.5.messenger', 1)
            ->where('converse.activity.6', ['date' => '2026-11-22', 'whatsapp' => 1, 'messenger' => 0])
            ->where('aiUsage.calls', 2)->where('aiUsage.paid_calls', 1)
            ->where('aiUsage.unsuccessful_calls', 1)->where('aiUsage.tokens', 30)
            ->where('knowledge.collections', 1)->where('knowledge.failed', 1))
        ->assertDontSee('Fictional customer')->assertDontSee('Fixture prompt')->assertDontSee('Fixture message body');

    $this->actingAs($staff)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('converse.waiting.0.id', $waiting)->where('aiUsage', null)->where('knowledge', null));
    $this->actingAs($demo)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('converse.waiting.0.id', $demoThread)->where('converse.open_demo_inquiries', 0)
        ->where('aiUsage', null)->where('knowledge.collections', 0))
        ->assertDontSee('Waiting customer');
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
