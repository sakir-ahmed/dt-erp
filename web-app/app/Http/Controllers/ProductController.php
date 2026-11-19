<?php

namespace App\Http\Controllers;

use App\Actions\Products\SaveProduct;
use App\Enums\WorkspacePermission;
use App\Http\Requests\SaveProductRequest;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $query = $request->query('search');
        $search = is_string($query) ? mb_substr(trim($query), 0, 100) : '';
        $products = Product::query()->where('is_demo', (bool) $request->user()?->is_demo)
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('name', "%{$search}%")->orWhereLike('sku', "%{$search}%")))
            ->orderBy('sku')
            ->simplePaginate(25, ['id', 'sku', 'name', 'unit', 'sale_price', 'active', 'stock_milliunits'])
            ->appends(['search' => $search]);

        return Inertia::render('Products/Index', [
            'search' => $search,
            'canManageProducts' => $request->user()?->can(WorkspacePermission::ManageProducts->value) ?? false,
            'products' => [
                'data' => $products->items(),
                'previous' => $products->previousPageUrl(),
                'next' => $products->nextPageUrl(),
            ],
        ]);
    }

    public function store(SaveProductRequest $request, SaveProduct $save): RedirectResponse
    {
        $save((bool) $request->user()?->is_demo, $request->validated());

        Inertia::flash('success', __('workspace.product_created'));

        return to_route('products.index');
    }

    public function update(SaveProductRequest $request, Product $product, SaveProduct $save): RedirectResponse
    {
        $save((bool) $request->user()?->is_demo, $request->validated(), $product);

        Inertia::flash('success', __('workspace.product_updated'));

        return to_route('products.index');
    }
}
