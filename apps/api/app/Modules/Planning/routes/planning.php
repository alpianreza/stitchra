<?php

use Illuminate\Support\Facades\Route;
use Modules\Planning\Http\Controllers\MrpController;
use Modules\Planning\Http\Controllers\ProductionPlanningController;
use Modules\Production\Http\Controllers\OperationalIntegrityController;
use Modules\Production\Http\Controllers\ProductionOrderController;
use Modules\Production\Http\Controllers\ProductionOutputAuthorityController;

Route::middleware(['auth:sanctum', 'company'])->group(function () {
    Route::get('planning/mrp-runs', [MrpController::class, 'index'])->middleware('permission:planning.mrp.view');
    Route::post('planning/mrp-runs', [MrpController::class, 'run'])->middleware('permission:planning.mrp.execute');
    Route::get('planning/mrp-runs/{mrpRun}', [MrpController::class, 'show'])->middleware('permission:planning.mrp.view');
    Route::post('planning/mrp-runs/{mrpRun}/convert-to-pr', [MrpController::class, 'convertToPr'])
        ->middleware(['permission:planning.mrp.execute', 'permission:purchasing.pr.create']);

    Route::get('planning/line-loading/capacity', [ProductionPlanningController::class, 'capacity'])->middleware('permission:planning.production.view');
    Route::get('planning/production-plans', [ProductionPlanningController::class, 'index'])->middleware('permission:planning.production.view');
    Route::post('planning/production-plans', [ProductionPlanningController::class, 'store'])->middleware('permission:planning.production.create');
    Route::put('planning/production-plans/{productionPlan}', [ProductionPlanningController::class, 'update'])->middleware('permission:planning.production.update');
    Route::post('planning/production-plans/{productionPlan}/loadings', [ProductionPlanningController::class, 'storeLoading'])->middleware('permission:planning.production.create');
    Route::put('planning/line-loadings/{lineLoading}', [ProductionPlanningController::class, 'updateLoading'])->middleware('permission:planning.production.update');

    Route::get('production/operational-integrity/authority', [OperationalIntegrityController::class, 'authority'])->middleware('permission:production.mo.view');
    Route::get('production/orders', [ProductionOrderController::class, 'index'])->middleware('permission:production.mo.view');
    Route::get('production/orders/{productionOrder}', [ProductionOrderController::class, 'show'])->middleware('permission:production.mo.view');
    Route::get('production/orders/{productionOrder}/matrix', [ProductionOrderController::class, 'matrix'])->middleware('permission:production.mo.view');
    Route::get('production/orders/{productionOrder}/output-authority', [ProductionOutputAuthorityController::class, 'show'])->middleware('permission:production.mo.view');
    Route::get('production/orders/{productionOrder}/operational-integrity', [OperationalIntegrityController::class, 'show'])->middleware('permission:production.mo.view');
    Route::post('production/orders/from-so/{salesOrder}', [ProductionOrderController::class, 'createFromSo'])->middleware('permission:production.mo.create');
    Route::post('production/orders/{productionOrder}/release', [ProductionOrderController::class, 'release'])->middleware('permission:production.mo.release');
    Route::post('production/orders/{productionOrder}/unrelease', [ProductionOrderController::class, 'unrelease'])->middleware('permission:production.mo.update');
});
