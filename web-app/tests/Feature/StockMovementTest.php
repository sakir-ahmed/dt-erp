<?php

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('retires stock routes and preserves the historical movement ledger and balance', function () {
    $user = User::factory()->create();
    $product = Product::create(['sku' => 'LEGACY-002', 'name' => 'Archived stock', 'unit' => 'pcs', 'sale_price' => '1.00']);
    $product->forceFill(['stock_milliunits' => 2000])->save();
    $operationId = (string) Str::uuid();
    $product->stockMovements()->create([
        'user_id' => $user->id,
        'operation_id' => $operationId,
        'delta_milliunits' => 2000,
        'balance_milliunits' => 2000,
        'reason' => 'Historical stock',
        'created_at' => now(),
    ]);

    $this->withoutVite()->actingAs($user);
    $this->get("/products/{$product->id}/stock")->assertNotFound();
    $this->post("/products/{$product->id}/stock", ['quantity' => '1'])->assertStatus(405);

    expect($product->refresh()->stock_milliunits)->toBe(2000);
    $this->assertDatabaseHas('stock_movements', ['operation_id' => $operationId, 'balance_milliunits' => 2000]);
});
