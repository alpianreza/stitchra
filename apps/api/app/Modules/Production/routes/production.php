<?php

use Illuminate\Support\Facades\Route;
use Modules\Production\Http\Controllers\MaterialIssueController;

// Material issue & leftover (Phase 6) — bagian dari Production
Route::middleware(['auth:sanctum', 'company'])->group(function () {
    Route::get('production/orders/{productionOrder}/issues', [MaterialIssueController::class, 'index']);
    Route::post('production/orders/{productionOrder}/issues', [MaterialIssueController::class, 'store']);
    Route::post('production/orders/{productionOrder}/issues/backflush', [MaterialIssueController::class, 'backflush']);
    Route::post('production/orders/{productionOrder}/rolls/{roll}/return', [MaterialIssueController::class, 'returnLeftover']);
});
