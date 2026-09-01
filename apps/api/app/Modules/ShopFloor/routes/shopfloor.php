<?php

use Illuminate\Support\Facades\Route;use Modules\ShopFloor\Http\Controllers\DeviceController;use Modules\ShopFloor\Http\Controllers\OfflineScanController;use Modules\ShopFloor\Http\Controllers\ReworkController;use Modules\ShopFloor\Http\Controllers\ScanController;
Route::middleware(['auth:sanctum','company'])->group(function(){
 Route::post('shopfloor/offline-scans/sync',[OfflineScanController::class,'sync'])->middleware('throttle:120,1');
 Route::get('shopfloor/devices',[DeviceController::class,'index'])->middleware('permission:production.output.view');Route::post('shopfloor/devices',[DeviceController::class,'store'])->middleware(['permission:production.output.create','throttle:10,1']);Route::delete('shopfloor/devices/{device}',[DeviceController::class,'destroy'])->middleware('permission:production.output.create');
 Route::post('shopfloor/scans',[ScanController::class,'scan'])->middleware('permission:production.output.create');Route::get('shopfloor/wip/{productionOrder}',[ScanController::class,'wip'])->middleware('permission:production.output.view');Route::get('shopfloor/lines/{line}/daily-output',[ScanController::class,'dailyOutput'])->middleware('permission:production.output.view');Route::post('shopfloor/rework',[ReworkController::class,'store'])->middleware('permission:production.output.create');Route::post('shopfloor/rework/{rework}/resolve',[ReworkController::class,'resolve'])->middleware('permission:production.output.create');
});
