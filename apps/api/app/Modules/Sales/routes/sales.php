<?php

use Illuminate\Support\Facades\Route;
use Modules\Sales\Http\Controllers\SalesOrderController;

Route::middleware(['auth:sanctum', 'company'])
    ->prefix('sales')
    ->group(function () {
        Route::get('orders', [SalesOrderController::class, 'index']);
        Route::post('orders', [SalesOrderController::class, 'store']);
        Route::get('orders/{salesOrder}', [SalesOrderController::class, 'show']);
        Route::post('orders/{salesOrder}/submit', [SalesOrderController::class, 'submit']);
        Route::post('orders/{salesOrder}/confirm', [SalesOrderController::class, 'confirm']);
    });
