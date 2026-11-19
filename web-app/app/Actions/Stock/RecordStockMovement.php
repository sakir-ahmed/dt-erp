<?php

namespace App\Actions\Stock;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordStockMovement
{
    /** @param array{direction: string, quantity: string, reason: string, operation_id: string} $data */
    public function __invoke(Product $product, int $userId, array $data): void
    {
        [$whole, $fraction] = array_pad(explode('.', $data['quantity'], 2), 2, '');
        $quantity = (int) $whole * 1000 + (int) str_pad($fraction, 3, '0');
        $delta = $data['direction'] === 'in' ? $quantity : -$quantity;

        DB::transaction(function () use ($product, $userId, $data, $delta) {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $previous = $locked->stockMovements()->where('operation_id', $data['operation_id'])->first();

            if ($previous !== null) {
                if ($previous->delta_milliunits !== $delta || $previous->reason !== $data['reason'] || $previous->user_id !== $userId) {
                    throw ValidationException::withMessages(['operation_id' => __('workspace.stock_operation_invalid')]);
                }

                return;
            }

            $balance = $locked->stock_milliunits + $delta;

            if ($balance < 0) {
                throw ValidationException::withMessages(['quantity' => __('workspace.insufficient_stock')]);
            }

            if ($delta > 0 && ! $locked->active) {
                throw ValidationException::withMessages(['direction' => __('workspace.inactive_stock_in')]);
            }

            if ($balance > 999999999999) {
                throw ValidationException::withMessages(['quantity' => __('workspace.stock_balance_limit')]);
            }

            $locked->forceFill(['stock_milliunits' => $balance])->save();
            $locked->stockMovements()->create([
                'user_id' => $userId,
                'operation_id' => $data['operation_id'],
                'delta_milliunits' => $delta,
                'balance_milliunits' => $balance,
                'reason' => $data['reason'],
            ]);
        }, attempts: 3);
    }
}
