<?php

use Illuminate\Support\Facades\Route;
use Modules\ProductDev\Http\Controllers\BomController;
use Modules\ProductDev\Http\Controllers\CostSheetController;
use Modules\ProductDev\Http\Controllers\RoutingController;
use Modules\ProductDev\Http\Controllers\SampleController;

Route::middleware(['auth:sanctum', 'company'])
    ->prefix('pd')
    ->group(function () {
        // BOM — BR-030 (versioned)
        Route::post('boms', [BomController::class, 'store']);
        Route::get('boms/{bomVersion}', [BomController::class, 'show']);
        Route::put('boms/{bomVersion}', [BomController::class, 'update']);
        Route::post('boms/{bomVersion}/submit', [BomController::class, 'submit']);

        // Routing — BR-033
        Route::post('routings', [RoutingController::class, 'store']);
        Route::get('routings/{routingVersion}', [RoutingController::class, 'show']);
        Route::post('routings/{routingVersion}/submit', [RoutingController::class, 'submit']);

        // Cost sheet — BR-100
        Route::post('cost-sheets/compute', [CostSheetController::class, 'compute']);
        Route::get('cost-sheets/{costSheet}', [CostSheetController::class, 'show']);
        Route::post('cost-sheets/{costSheet}/price', [CostSheetController::class, 'setPrice']);
        Route::post('cost-sheets/{costSheet}/submit', [CostSheetController::class, 'submit']);

        // Sample cycle
        Route::post('samples', [SampleController::class, 'store']);
        Route::post('samples/{sample}/approvals', [SampleController::class, 'addApproval']);
    });
