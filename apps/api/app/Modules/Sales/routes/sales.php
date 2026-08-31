<?php

use Illuminate\Support\Facades\Route;
use Modules\Sales\Http\Controllers\SalesOrderController;

Route::middleware(['auth:sanctum', 'company'])
    ->prefix('sales')
    ->group(function () {
        Route::get('orders', [SalesOrderController::class, 'index'])->middleware('permission:sales.order.view');
        Route::post('orders', [SalesOrderController::class, 'store'])->middleware('permission:sales.order.create');
        Route::get('orders/{salesOrder}', [SalesOrderController::class, 'show'])->middleware('permission:sales.order.view');
        Route::post('orders/{salesOrder}/submit', [SalesOrderController::class, 'submit'])->middleware('permission:sales.order.submit');
        Route::post('orders/{salesOrder}/confirm', [SalesOrderController::class, 'confirm'])->middleware('permission:sales.order.approve');
    });
