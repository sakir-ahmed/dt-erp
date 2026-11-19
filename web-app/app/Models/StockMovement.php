<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'operation_id', 'delta_milliunits', 'balance_milliunits', 'reason'])]
class StockMovement extends Model
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['delta_milliunits' => 'integer', 'balance_milliunits' => 'integer'];
    }
}
