<?php

use Illuminate\Support\Facades\Route;
use Modules\Planning\Http\Controllers\MrpController;
use Modules\Production\Http\Controllers\ProductionOrderController;

Route::middleware(['auth:sanctum', 'company'])->group(function () {
    Route::get('planning/mrp-runs', [MrpController::class, 'index'])->middleware('permission:planning.mrp.view');
    Route::post('planning/mrp-runs', [MrpController::class, 'run'])->middleware('permission:planning.mrp.execute');
    Route::get('planning/mrp-runs/{mrpRun}', [MrpController::class, 'show'])->middleware('permission:planning.mrp.view');
    Route::post('planning/mrp-runs/{mrpRun}/convert-to-pr', [MrpController::class, 'convertToPr'])
        ->middleware(['permission:planning.mrp.execute', 'permission:purchasing.pr.create']);

    Route::get('production/orders', [ProductionOrderController::class, 'index'])->middleware('permission:production.mo.view');
    Route::get('production/orders/{productionOrder}', [ProductionOrderController::class, 'show'])->middleware('permission:production.mo.view');
    Route::post('production/orders/from-so/{salesOrder}', [ProductionOrderController::class, 'createFromSo'])->middleware('permission:production.mo.create');
    Route::post('production/orders/{productionOrder}/release', [ProductionOrderController::class, 'release'])->middleware('permission:production.mo.release');
    Route::post('production/orders/{productionOrder}/unrelease', [ProductionOrderController::class, 'unrelease'])->middleware('permission:production.mo.update');
});
