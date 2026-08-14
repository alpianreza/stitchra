<?php

use Illuminate\Support\Facades\Route;
use Modules\Planning\Http\Controllers\MrpController;
use Modules\Production\Http\Controllers\ProductionOrderController;

Route::middleware(['auth:sanctum', 'company'])->group(function () {
    // Planning / MRP — BR-043/045
    Route::get('planning/mrp-runs', [MrpController::class, 'index']);
    Route::post('planning/mrp-runs', [MrpController::class, 'run']);
    Route::get('planning/mrp-runs/{mrpRun}', [MrpController::class, 'show']);
    Route::post('planning/mrp-runs/{mrpRun}/convert-to-pr', [MrpController::class, 'convertToPr']);

    // Manufacturing Orders — BR-060
    Route::get('production/orders', [ProductionOrderController::class, 'index']);
    Route::get('production/orders/{productionOrder}', [ProductionOrderController::class, 'show']);
    Route::post('production/orders/from-so/{salesOrder}', [ProductionOrderController::class, 'createFromSo']);
    Route::post('production/orders/{productionOrder}/release', [ProductionOrderController::class, 'release']);
    Route::post('production/orders/{productionOrder}/unrelease', [ProductionOrderController::class, 'unrelease']);
});
