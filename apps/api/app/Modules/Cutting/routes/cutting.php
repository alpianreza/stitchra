<?php

use Illuminate\Support\Facades\Route;
use Modules\Cutting\Http\Controllers\CutOrderController;

Route::middleware(['auth:sanctum', 'company'])->group(function () {
    Route::post('cutting/orders/from-mo/{productionOrder}', [CutOrderController::class, 'store']);
    Route::post('cutting/orders/{cutOrder}/markers', [CutOrderController::class, 'recordMarker']);
    Route::post('cutting/orders/{cutOrder}/lines/{line}/bundles', [CutOrderController::class, 'generateBundles']);
    Route::post('cutting/orders/{cutOrder}/complete', [CutOrderController::class, 'complete']);
});
