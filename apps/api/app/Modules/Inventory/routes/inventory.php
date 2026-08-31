<?php

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\InventoryOpsController;

Route::middleware(['auth:sanctum', 'company'])
    ->prefix('inventory')
    ->group(function () {
        Route::get('stock', [InventoryOpsController::class, 'stock'])->middleware('permission:inventory.stock.view');
        Route::get('rolls', [InventoryOpsController::class, 'rolls'])->middleware('permission:inventory.fabric-roll.view');

        Route::post('transfers', [InventoryOpsController::class, 'createTransfer'])->middleware('permission:inventory.transfer.create');
        Route::post('transfers/{stockTransfer}/post', [InventoryOpsController::class, 'postTransfer'])->middleware('permission:inventory.transfer.submit');
        Route::post('transfers/{stockTransfer}/receive', [InventoryOpsController::class, 'receiveTransfer'])->middleware('permission:inventory.transfer.submit');

        Route::post('adjustments', [InventoryOpsController::class, 'createAdjustment'])->middleware('permission:inventory.adjustment.create');
        Route::post('adjustments/{stockAdjustment}/submit', [InventoryOpsController::class, 'submitAdjustment'])->middleware('permission:inventory.adjustment.submit');

        Route::post('opnames', [InventoryOpsController::class, 'createOpname'])->middleware('permission:inventory.opname.create');
        Route::post('opnames/{stockOpname}/counts', [InventoryOpsController::class, 'recordCounts'])->middleware('permission:inventory.opname.submit');
    });
