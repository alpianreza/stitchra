<?php

use Illuminate\Support\Facades\Route;
use Modules\Cutting\Http\Controllers\CutOrderController;

Route::middleware(['auth:sanctum', 'company'])->group(function () {
    Route::post('cutting/orders/from-mo/{productionOrder}', [CutOrderController::class, 'store'])
        ->middleware('permission:cutting.order.execute');
    Route::post('cutting/orders/{cutOrder}/markers', [CutOrderController::class, 'recordMarker'])
        ->middleware('permission:cutting.marker.execute');
    Route::post('cutting/orders/{cutOrder}/lines/{line}/bundles', [CutOrderController::class, 'generateBundles'])
        ->middleware('permission:cutting.bundle.execute');
    Route::post('cutting/orders/{cutOrder}/complete', [CutOrderController::class, 'complete'])
        ->middleware('permission:cutting.order.execute');
});
