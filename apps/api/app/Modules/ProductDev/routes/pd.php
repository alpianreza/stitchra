<?php

use Illuminate\Support\Facades\Route;
use Modules\ProductDev\Http\Controllers\BomController;
use Modules\ProductDev\Http\Controllers\CostSheetController;
use Modules\ProductDev\Http\Controllers\RoutingController;
use Modules\ProductDev\Http\Controllers\SampleController;

Route::middleware(['auth:sanctum', 'company'])
    ->prefix('pd')
    ->group(function () {
        Route::post('boms', [BomController::class, 'store'])->middleware('permission:pd.bom.create');
        Route::get('boms/{bomVersion}', [BomController::class, 'show'])->middleware('permission:pd.bom.view');
        Route::put('boms/{bomVersion}', [BomController::class, 'update'])->middleware('permission:pd.bom.update');
        Route::post('boms/{bomVersion}/submit', [BomController::class, 'submit'])->middleware('permission:pd.bom.submit');

        Route::post('routings', [RoutingController::class, 'store'])->middleware('permission:pd.routing.create');
        Route::get('routings/{routingVersion}', [RoutingController::class, 'show'])->middleware('permission:pd.routing.view');
        Route::post('routings/{routingVersion}/submit', [RoutingController::class, 'submit'])->middleware('permission:pd.routing.submit');

        Route::post('cost-sheets/compute', [CostSheetController::class, 'compute'])->middleware('permission:pd.costing.create');
        Route::get('cost-sheets/{costSheet}', [CostSheetController::class, 'show'])->middleware('permission:pd.costing.view');
        Route::post('cost-sheets/{costSheet}/price', [CostSheetController::class, 'setPrice'])->middleware('permission:pd.costing.update');
        Route::post('cost-sheets/{costSheet}/submit', [CostSheetController::class, 'submit'])->middleware('permission:pd.costing.submit');

        Route::post('samples', [SampleController::class, 'store'])->middleware('permission:pd.sample.create');
        Route::post('samples/{sample}/approvals', [SampleController::class, 'addApproval'])->middleware('permission:pd.sample.submit');
    });
