<?php

use Illuminate\Support\Facades\Route;
use Modules\ShopFloor\Http\Controllers\ScanController;

Route::middleware(['auth:sanctum', 'company'])->group(function () {
    Route::post('shopfloor/scans', [ScanController::class, 'scan']);
    Route::get('shopfloor/wip/{productionOrder}', [ScanController::class, 'wip']);
    Route::get('shopfloor/lines/{line}/daily-output', [ScanController::class, 'dailyOutput']);
});
