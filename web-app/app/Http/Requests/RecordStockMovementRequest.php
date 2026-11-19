<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

class RecordStockMovementRequest extends FormRequest
{
    public function rules(): array
    {
        $product = $this->route('product');
        abort_unless($product instanceof Product && $product->is_demo === (bool) $this->user()?->is_demo, 404);

        return [
            'direction' => ['required', 'in:in,out'],
            'quantity' => ['bail', 'required', 'string', 'regex:/^\d{1,9}(?:\.\d{1,3})?$/D', 'gt:0', 'numeric'],
            'reason' => ['required', 'string', 'max:255'],
            'operation_id' => ['required', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'direction.*' => __('workspace.stock_direction_invalid'),
            'quantity.*' => __('workspace.stock_quantity_invalid'),
            'reason.*' => __('workspace.stock_reason_invalid'),
            'operation_id.*' => __('workspace.stock_operation_invalid'),
        ];
    }
}
