<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\ApprovalFlowController;
use Modules\Core\Http\Controllers\ApprovalInboxController;

Route::middleware(['auth:sanctum', 'company'])
    ->prefix('approvals')
    ->group(function () {
        // Konfigurasi flow hanya untuk user dengan permission administratif.
        Route::middleware('permission:core.approval.manage')->group(function () {
            Route::get('roles', [ApprovalFlowController::class, 'roles']);
            Route::get('flows', [ApprovalFlowController::class, 'index']);
            Route::post('flows', [ApprovalFlowController::class, 'store']);
            Route::post('flows/{approvalFlow}/deactivate', [ApprovalFlowController::class, 'deactivate']);
        });

        // Otorisasi tindakan inbox tetap divalidasi terhadap role step aktif oleh ApprovalEngine.
        Route::get('pending', [ApprovalInboxController::class, 'pending']);
        Route::get('{approvalRequest}', [ApprovalInboxController::class, 'show'])->whereNumber('approvalRequest');
        Route::post('{approvalRequest}/approve', [ApprovalInboxController::class, 'approve'])->whereNumber('approvalRequest');
        Route::post('{approvalRequest}/reject', [ApprovalInboxController::class, 'reject'])->whereNumber('approvalRequest');
        Route::post('{approvalRequest}/revision', [ApprovalInboxController::class, 'requestRevision'])->whereNumber('approvalRequest');
    });
