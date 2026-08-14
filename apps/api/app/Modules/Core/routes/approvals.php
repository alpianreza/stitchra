<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\ApprovalFlowController;
use Modules\Core\Http\Controllers\ApprovalInboxController;

// Kotak masuk approval — otorisasi role-based via ApprovalEngine (BR-015/110)
Route::middleware(['auth:sanctum', 'company'])
    ->prefix('approvals')
    ->group(function () {
        // Setup flows (admin)
        Route::get('roles', [ApprovalFlowController::class, 'roles']);
        Route::get('flows', [ApprovalFlowController::class, 'index']);
        Route::post('flows', [ApprovalFlowController::class, 'store']);
        Route::post('flows/{approvalFlow}/deactivate', [ApprovalFlowController::class, 'deactivate']);

        // Inbox (semua user ber-role)
        Route::get('pending', [ApprovalInboxController::class, 'pending']);
        Route::get('{approvalRequest}', [ApprovalInboxController::class, 'show'])->whereNumber('approvalRequest');
        Route::post('{approvalRequest}/approve', [ApprovalInboxController::class, 'approve'])->whereNumber('approvalRequest');
        Route::post('{approvalRequest}/reject', [ApprovalInboxController::class, 'reject'])->whereNumber('approvalRequest');
        Route::post('{approvalRequest}/revision', [ApprovalInboxController::class, 'requestRevision'])->whereNumber('approvalRequest');
    });
