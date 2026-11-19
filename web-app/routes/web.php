<?php

use App\Enums\WorkspacePermission;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DemoSessionController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\UserDirectoryController;
use App\Http\Requests\UpdateLocaleRequest;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login/demo/{user}', DemoSessionController::class)
        ->whereNumber('user')
        ->middleware('throttle:10,1')
        ->name('login.demo');
    Route::post('/login', [SessionController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/users', [UserDirectoryController::class, 'index'])
        ->can(WorkspacePermission::ViewUsers->value)
        ->name('users.index');
    Route::post('/users', [UserDirectoryController::class, 'store'])
        ->can(WorkspacePermission::ManageUsers->value)
        ->name('users.store');
    Route::patch('/users/{user}', [UserDirectoryController::class, 'update'])
        ->can(WorkspacePermission::ManageUsers->value)
        ->name('users.update');
    Route::get('/products', [ProductController::class, 'index'])->can(WorkspacePermission::ViewProducts->value)->name('products.index');
    Route::post('/products', [ProductController::class, 'store'])->can(WorkspacePermission::ManageProducts->value)->name('products.store');
    Route::patch('/products/{product}', [ProductController::class, 'update'])->can(WorkspacePermission::ManageProducts->value)->name('products.update');
    Route::get('/products/{product}/stock', [StockMovementController::class, 'index'])
        ->can(WorkspacePermission::ViewProducts->value)
        ->name('products.stock.index');
    Route::post('/products/{product}/stock', [StockMovementController::class, 'store'])
        ->can(WorkspacePermission::ManageStock->value)
        ->name('products.stock.store');
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
});

Route::post('/locale', function (UpdateLocaleRequest $request) {
    $request->session()->put('locale', $request->validated('locale'));

    return back(fallback: route($request->user() ? 'dashboard' : 'login'));
})->name('locale.update');

Route::fallback(fn () => abort(404));
