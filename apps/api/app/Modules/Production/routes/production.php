<?php

use Illuminate\Support\Facades\Route;
use Modules\Production\Http\Controllers\MaterialIssueController;

Route::middleware(['auth:sanctum', 'company'])->group(function () {
    Route::get('production/orders/{productionOrder}/issues', [MaterialIssueController::class, 'index'])
        ->middleware('permission:production.issue.view');
    Route::post('production/orders/{productionOrder}/issues', [MaterialIssueController::class, 'store'])
        ->middleware('permission:production.issue.execute');
    Route::post('production/orders/{productionOrder}/issues/backflush', [MaterialIssueController::class, 'backflush'])
        ->middleware('permission:production.issue.execute');
    Route::post('production/orders/{productionOrder}/rolls/{roll}/return', [MaterialIssueController::class, 'returnLeftover'])
        ->middleware('permission:cutting.leftover.execute');
});
