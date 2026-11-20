<?php

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('retires the standalone product routes and preserves historical products', function () {
    $product = Product::create(['sku' => 'LEGACY-001', 'name' => 'Archived product', 'unit' => 'pcs', 'sale_price' => '12.50']);

    $this->withoutVite()->actingAs(User::factory()->create());
    $this->get('/products')->assertNotFound();
    $this->post('/products', ['sku' => 'NEW'])->assertStatus(405);
    $this->patch("/products/{$product->id}", ['name' => 'Changed'])->assertStatus(405);
    $this->assertDatabaseHas('products', ['id' => $product->id, 'sku' => 'LEGACY-001', 'name' => 'Archived product']);
    expect($product->refresh()->sale_price)->toBe('12.50');
});
