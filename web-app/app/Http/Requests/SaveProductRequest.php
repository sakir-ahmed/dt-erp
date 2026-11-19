<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveProductRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('sku'))) {
            $this->merge(['sku' => strtoupper(trim($this->input('sku')))]);
        }
    }

    public function rules(): array
    {
        $product = $this->route('product');
        $sku = Rule::unique('products', 'sku')->where('is_demo', (bool) $this->user()?->is_demo);

        if ($product instanceof Product) {
            abort_unless($product->is_demo === (bool) $this->user()?->is_demo, 404);
            $sku->ignore($product);
        }

        return [
            'sku' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9_-]+$/D', $sku],
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:32'],
            'sale_price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'sku.unique' => __('workspace.sku_taken'),
            'sku.*' => __('workspace.sku_invalid'),
            'name.*' => __('workspace.name_invalid'),
            'unit.*' => __('workspace.unit_invalid'),
            'sale_price.*' => __('workspace.price_invalid'),
            'active.*' => __('workspace.status_invalid'),
        ];
    }
}
