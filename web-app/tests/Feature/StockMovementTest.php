<?php

use App\Enums\WorkspaceRole;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    $this->withoutVite();
});

it('keeps an exact stock balance and records each operation once', function () {
    $admin = User::factory()->create()->assignRole(WorkspaceRole::Administrator->value);
    $product = Product::create(['sku' => 'STOCK', 'name' => 'Stocked product', 'unit' => 'kg', 'sale_price' => '1.00']);
    $url = route('products.stock.store', $product);
    $movement = ['direction' => 'in', 'quantity' => '5.125', 'reason' => 'Opening stock', 'operation_id' => (string) Str::uuid()];

    $this->actingAs($admin)->post($url, $movement)->assertRedirect($url);
    $this->post($url, $movement)->assertRedirect($url);
    $this->post($url, [...$movement, 'quantity' => '5.126'])->assertSessionHasErrors('operation_id');
    $this->post($url, [...$movement, 'direction' => 'out', 'quantity' => '6', 'operation_id' => (string) Str::uuid()])->assertSessionHasErrors('quantity');
    $this->post($url, [...$movement, 'direction' => 'out', 'quantity' => '0.225', 'operation_id' => (string) Str::uuid()])->assertRedirect($url);

    expect($product->refresh()->stock_milliunits)->toBe(4900);
    expect($product->stockMovements()->count())->toBe(2);

    $this->get($url)->assertInertia(fn (Assert $page) => $page
        ->component('Stock/Index')
        ->where('product.stock_milliunits', 4900)
        ->where('movements.data.0.delta', -225)
        ->where('movements.data.0.balance', 4900));
});

it('enforces stock permissions, demo isolation and inactive product rules', function () {
    $admin = User::factory()->create()->assignRole(WorkspaceRole::Administrator->value);
    $staff = User::factory()->create()->assignRole(WorkspaceRole::Staff->value);
    $demo = User::factory()->create(['is_demo' => true])->assignRole(WorkspaceRole::Administrator->value);
    $product = Product::create(['sku' => 'STOCK', 'name' => 'Inactive product', 'unit' => 'kg', 'sale_price' => '1.00', 'active' => false]);
    $product->forceFill(['stock_milliunits' => 2000])->save();
    $url = route('products.stock.store', $product);
    $movement = ['direction' => 'in', 'quantity' => '1', 'reason' => 'Adjustment', 'operation_id' => (string) Str::uuid()];

    $this->actingAs($staff)->get($url)->assertOk();
    $this->post($url, $movement)->assertForbidden();
    $this->actingAs($demo)->get($url)->assertNotFound();
    $this->post($url, $movement)->assertNotFound();
    $this->actingAs($admin)->post($url, $movement)->assertSessionHasErrors('direction');
    $this->post($url, [...$movement, 'direction' => 'out'])->assertRedirect($url);
    expect($product->refresh()->stock_milliunits)->toBe(1000);
});
