<?php

use Illuminate\Support\Facades\Route;
use Modules\Cutting\Http\Controllers\CutOrderController;
use Modules\Cutting\Http\Controllers\LayController;

Route::middleware(['auth:sanctum', 'company'])->group(function () {
    Route::post('cutting/orders/from-mo/{productionOrder}', [CutOrderController::class, 'store'])
        ->middleware('permission:cutting.order.execute');
    Route::post('cutting/orders/{cutOrder}/markers', [CutOrderController::class, 'recordMarker'])
        ->middleware('permission:cutting.marker.execute');
    Route::post('cutting/orders/{cutOrder}/complete', [CutOrderController::class, 'complete'])
        ->middleware('permission:cutting.order.execute');

    Route::get('cutting/buyers/{customer}/shade-rule', [LayController::class, 'buyerRule'])
        ->middleware('permission:master.customer.view');
    Route::put('cutting/buyers/{customer}/shade-rule', [LayController::class, 'configureBuyer'])
        ->middleware('permission:master.customer.update');
    Route::get('cutting/orders/{cutOrder}/lays', [LayController::class, 'index'])
        ->middleware('permission:cutting.order.view');
    Route::post('cutting/orders/{cutOrder}/lays', [LayController::class, 'store'])
        ->middleware('permission:cutting.lay.execute');
    Route::post('cutting/lays/{lay}/rolls', [LayController::class, 'addRoll'])
        ->middleware('permission:cutting.lay.execute');
    Route::post('cutting/lays/{lay}/shade-overrides', [LayController::class, 'requestOverride'])
        ->middleware('permission:cutting.lay.execute');
    Route::post('cutting/shade-overrides/{shadeOverrideRequest}/apply', [LayController::class, 'applyOverride'])
        ->middleware('permission:cutting.lay.execute');
    Route::post('cutting/lays/{lay}/outputs', [LayController::class, 'output'])
        ->middleware('permission:cutting.order.execute');
    Route::post('cutting/outputs/{cutOutput}/bundles', [LayController::class, 'bundles'])
        ->middleware('permission:cutting.bundle.execute');
    Route::post('cutting/lays/{lay}/complete', [LayController::class, 'complete'])
        ->middleware('permission:cutting.lay.execute');
});
