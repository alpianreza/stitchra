<?php

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\InventoryOpsController;

Route::middleware(['auth:sanctum', 'company'])
    ->prefix('inventory')
    ->group(function () {
        Route::get('stock', [InventoryOpsController::class, 'stock']);
        Route::get('rolls', [InventoryOpsController::class, 'rolls']);

        Route::post('transfers', [InventoryOpsController::class, 'createTransfer']);
        Route::post('transfers/{stockTransfer}/post', [InventoryOpsController::class, 'postTransfer']);
        Route::post('transfers/{stockTransfer}/receive', [InventoryOpsController::class, 'receiveTransfer']);

        Route::post('adjustments', [InventoryOpsController::class, 'createAdjustment']);
        Route::post('adjustments/{stockAdjustment}/submit', [InventoryOpsController::class, 'submitAdjustment']);

        Route::post('opnames', [InventoryOpsController::class, 'createOpname']);
        Route::post('opnames/{stockOpname}/counts', [InventoryOpsController::class, 'recordCounts']);
    });
