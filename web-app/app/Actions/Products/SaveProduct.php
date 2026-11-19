<?php

namespace App\Actions\Products;

use App\Models\Product;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class SaveProduct
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(bool $demo, array $attributes, ?Product $product = null): void
    {
        $product ??= (new Product)->forceFill(['is_demo' => $demo]);

        try {
            $product->fill($attributes)->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['sku' => __('workspace.sku_taken')]);
        }
    }
}
