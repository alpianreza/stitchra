<?php

use Illuminate\Support\Facades\Route;
use Modules\Finance\Http\Controllers\ArApController;
use Modules\Finance\Http\Controllers\CostingController;
use Modules\Finance\Http\Controllers\JournalController;

Route::middleware(['auth:sanctum', 'company'])
    ->prefix('finance')
    ->group(function () {
        // GL — BR-101/103
        Route::post('journals', [JournalController::class, 'store']);
        Route::post('journals/{journal}/reverse', [JournalController::class, 'reverse']);
        Route::get('trial-balance', [JournalController::class, 'trialBalance']);
        Route::post('periods/close', [JournalController::class, 'closePeriod']);
        Route::post('account-mappings', [JournalController::class, 'setMapping']);

        // AR/AP — BR-050/102
        Route::post('ar/invoices/from-shipment/{shipment}', [ArApController::class, 'createArInvoice']);
        Route::post('ar/invoices/{arInvoice}/payments', [ArApController::class, 'payAr']);
        Route::post('ap/invoices/{supplierInvoice}/payments', [ArApController::class, 'payAp']);
        Route::get('ar/aging', [ArApController::class, 'agingAr']);
        Route::get('ap/aging', [ArApController::class, 'agingAp']);

        // Costing aktual & BEP — BR-080/081/104
        Route::get('costing/mo/{productionOrder}/actual', [CostingController::class, 'actual']);
        Route::post('bep/style/{style}', [CostingController::class, 'bepStyle']);
        Route::post('bep/factory', [CostingController::class, 'bepFactory']);
    });
