<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['sku', 'name', 'unit', 'sale_price', 'active'])]
class Product extends Model
{
    /** @return HasMany<StockMovement, $this> */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    protected function casts(): array
    {
        return ['sale_price' => 'decimal:2', 'active' => 'boolean', 'is_demo' => 'boolean', 'stock_milliunits' => 'integer'];
    }
}
