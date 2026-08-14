<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\ApprovalInboxController;

// Kotak masuk approval — otorisasi role-based via ApprovalEngine (BR-015/110)
Route::middleware(['auth:sanctum', 'company'])
    ->prefix('approvals')
    ->group(function () {
        Route::get('pending', [ApprovalInboxController::class, 'pending']);
        Route::get('{approvalRequest}', [ApprovalInboxController::class, 'show']);
        Route::post('{approvalRequest}/approve', [ApprovalInboxController::class, 'approve']);
        Route::post('{approvalRequest}/reject', [ApprovalInboxController::class, 'reject']);
        Route::post('{approvalRequest}/revision', [ApprovalInboxController::class, 'requestRevision']);
    });
