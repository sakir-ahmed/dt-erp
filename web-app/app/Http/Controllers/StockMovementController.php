<?php

namespace App\Http\Controllers;

use App\Actions\Stock\RecordStockMovement;
use App\Enums\WorkspacePermission;
use App\Http\Requests\RecordStockMovementRequest;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockMovementController extends Controller
{
    public function index(Request $request, Product $product): Response
    {
        abort_unless($product->is_demo === (bool) $request->user()?->is_demo, 404);

        $movements = $product->stockMovements()->latest('id')->simplePaginate(25)
            ->through(fn (StockMovement $movement) => [
                'id' => $movement->id,
                'delta' => $movement->delta_milliunits,
                'balance' => $movement->balance_milliunits,
                'reason' => $movement->reason,
                'recordedAt' => $movement->created_at->toISOString(),
            ]);

        return Inertia::render('Stock/Index', [
            'product' => $product->only(['id', 'sku', 'name', 'unit', 'active', 'stock_milliunits']),
            'canManageStock' => $request->user()?->can(WorkspacePermission::ManageStock->value) ?? false,
            'movements' => [
                'data' => $movements->items(),
                'previous' => $movements->previousPageUrl(),
                'next' => $movements->nextPageUrl(),
            ],
        ]);
    }

    public function store(RecordStockMovementRequest $request, Product $product, RecordStockMovement $record): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null && $product->is_demo === (bool) $user->is_demo, 404);

        $record($product, $user->id, $request->validated());

        Inertia::flash('success', __('workspace.stock_recorded'));

        return to_route('products.stock.index', $product);
    }
}
