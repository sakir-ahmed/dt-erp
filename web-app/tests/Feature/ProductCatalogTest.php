<?php

use App\Enums\WorkspaceRole;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    $this->withoutVite();
});

it('creates, searches and deactivates a product while preserving its price', function () {
    $admin = User::factory()->create()->assignRole(WorkspaceRole::Administrator->value);
    $attributes = ['sku' => 'part-001', 'name' => 'Example Part', 'unit' => 'pcs', 'sale_price' => '10.50', 'active' => true];

    $this->actingAs($admin)->post(route('products.store'), $attributes)->assertRedirect(route('products.index'));

    $product = Product::query()->firstOrFail();
    expect($product->sku)->toBe('PART-001');
    expect($product->sale_price)->toBe('10.50');

    $this->from(route('products.index'))->post(route('products.store'), $attributes)->assertSessionHasErrors('sku');
    $this->patch(route('products.update', $product), [...$attributes, 'active' => false])->assertRedirect(route('products.index'));

    $this->get(route('products.index', ['search' => 'PART-001']))
        ->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->where('products.data.0.active', false));
});

it('allows staff to browse without writing and separates demo records', function () {
    $admin = User::factory()->create()->assignRole(WorkspaceRole::Administrator->value);
    $staff = User::factory()->create()->assignRole(WorkspaceRole::Staff->value);
    $demo = User::factory()->create(['is_demo' => true])->assignRole(WorkspaceRole::Administrator->value);
    $attributes = ['sku' => 'SAME', 'name' => 'Regular product', 'unit' => 'pcs', 'sale_price' => '0.00', 'active' => true];

    $this->actingAs($admin)->post(route('products.store'), $attributes)->assertRedirect();
    $regular = Product::query()->firstOrFail();

    $this->actingAs($staff)->get(route('products.index'))->assertOk();
    $this->post(route('products.store'), $attributes)->assertForbidden();

    $this->actingAs($demo)->patch(route('products.update', $regular), $attributes)->assertNotFound();
    $this->post(route('products.store'), [...$attributes, 'name' => 'Demo product'])->assertRedirect();
    $this->get(route('products.index'))
        ->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->where('products.data.0.name', 'Demo product'));

    $demoProduct = Product::query()->where('is_demo', true)->firstOrFail();
    $this->actingAs($admin)->patch(route('products.update', $demoProduct), $attributes)->assertNotFound();
});
