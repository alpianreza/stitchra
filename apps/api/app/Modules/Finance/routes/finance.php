<?php

use Illuminate\Support\Facades\Route;
use Modules\Finance\Http\Controllers\ArApController;
use Modules\Finance\Http\Controllers\BankReconciliationController;
use Modules\Finance\Http\Controllers\CostingController;
use Modules\Finance\Http\Controllers\FxRevaluationController;
use Modules\Finance\Http\Controllers\JournalController;
use Modules\Finance\Http\Controllers\ManufacturingValuationController;
use Modules\Finance\Http\Controllers\OperationalPostingController;
use Modules\Finance\Http\Controllers\PeriodCloseController;
use Modules\Finance\Http\Controllers\TaxController;
use Modules\Finance\Http\Controllers\ValuationBoundaryController;

Route::middleware(['auth:sanctum', 'company'])->prefix('finance')->group(function () {
    Route::post('journals', [JournalController::class, 'store'])->middleware('permission:finance.journal.create');
    Route::post('journals/{journal}/reverse', [JournalController::class, 'reverse'])->middleware('permission:finance.journal.approve');
    Route::get('journals/{journal}/lineage', [OperationalPostingController::class, 'lineage'])->middleware('permission:finance.report.view');
    Route::get('trial-balance', [JournalController::class, 'trialBalance'])->middleware('permission:finance.report.view');
    Route::get('gl/operational-authority', [OperationalPostingController::class, 'authority'])->middleware('permission:finance.report.view');
    Route::post('gl/operational-postings/goods-receipts/{goodsReceipt}', [OperationalPostingController::class, 'postGoodsReceipt'])->middleware('permission:finance.journal.create');
    Route::get('gl/valuation-authority', [ValuationBoundaryController::class, 'authority'])->middleware('permission:finance.report.view');
    Route::get('gl/valuation-boundaries/production-orders/{productionOrder}', [ValuationBoundaryController::class, 'productionOrder'])->middleware('permission:finance.report.view');
    Route::get('gl/valuation-boundaries/shipments/{shipment}', [ValuationBoundaryController::class, 'shipment'])->middleware('permission:finance.report.view');

    Route::post('valuation/allocation-profiles', [ManufacturingValuationController::class, 'createProfile'])->middleware('permission:master.finance.manage');
    Route::post('valuation/allocation-profiles/{profile}/activate', [ManufacturingValuationController::class, 'activateProfile'])->middleware('permission:master.finance.manage');
    Route::post('valuation/production-orders/{productionOrder}/eligibility', [ManufacturingValuationController::class, 'createEligibility'])->middleware('permission:master.finance.manage');
    Route::post('valuation/eligibilities/{eligibility}/activate', [ManufacturingValuationController::class, 'activateEligibility'])->middleware('permission:master.finance.manage');
    Route::post('valuation/production-orders/{productionOrder}/wip-transfers/{transfer}', [ManufacturingValuationController::class, 'valueWip'])->middleware('permission:finance.journal.create');
    Route::post('valuation/production-orders/{productionOrder}/fg-receipts/{movement}', [ManufacturingValuationController::class, 'valueFg'])->middleware('permission:finance.journal.create');
    Route::post('valuation/production-orders/{productionOrder}/freezes', [ManufacturingValuationController::class, 'createFreeze'])->middleware('permission:finance.journal.create');
    Route::post('valuation/freezes/{freeze}/apply', [ManufacturingValuationController::class, 'applyFreeze'])->middleware('permission:finance.journal.approve');
    Route::get('valuation/production-orders/{productionOrder}', [ManufacturingValuationController::class, 'show'])->middleware('permission:costing.actual.view');

    Route::post('period-close/prepare', [PeriodCloseController::class, 'prepare'])->middleware('permission:finance.period-closing.execute');
    Route::post('period-close/{periodCloseRun}/approve', [PeriodCloseController::class, 'approve'])->middleware('permission:finance.period-closing.execute');
    Route::post('period-close/{periodCloseRun}/close', [PeriodCloseController::class, 'close'])->middleware('permission:finance.period-closing.execute');
    Route::post('fx-revaluations', [FxRevaluationController::class, 'run'])->middleware('permission:finance.period-closing.execute');
    Route::post('fx-revaluations/{fxRevaluationRun}/reverse', [FxRevaluationController::class, 'reverse'])->middleware('permission:finance.period-closing.execute');
    Route::post('account-mappings', [JournalController::class, 'setMapping'])->middleware('permission:master.finance.manage');
    Route::get('tax-codes', [TaxController::class, 'index'])->middleware('permission:master.finance.manage');
    Route::post('tax-codes', [TaxController::class, 'store'])->middleware('permission:master.finance.manage');
    Route::delete('tax-codes/{taxCode}', [TaxController::class, 'deactivate'])->middleware('permission:master.finance.manage');
    Route::get('bank-accounts', [BankReconciliationController::class, 'accounts'])->middleware('permission:finance.payment.view');
    Route::post('bank-accounts', [BankReconciliationController::class, 'createAccount'])->middleware('permission:master.finance.manage');
    Route::post('bank-accounts/{bankAccount}/statements', [BankReconciliationController::class, 'import'])->middleware('permission:finance.payment.create');
    Route::get('bank-statements/{bankStatementImport}', [BankReconciliationController::class, 'show'])->middleware('permission:finance.payment.view');
    Route::post('bank-statement-lines/{bankStatementLine}/matches', [BankReconciliationController::class, 'match'])->middleware('permission:finance.payment.create');
    Route::post('bank-statement-lines/{bankStatementLine}/ignore', [BankReconciliationController::class, 'ignore'])->middleware('permission:finance.payment.create');
    Route::post('bank-statement-lines/{bankStatementLine}/bank-fee', [BankReconciliationController::class, 'fee'])->middleware('permission:finance.payment.create');
    Route::post('bank-statements/{bankStatementImport}/reconcile', [BankReconciliationController::class, 'reconcile'])->middleware('permission:finance.period-closing.execute');
    Route::post('ar/invoices/from-shipment/{shipment}', [ArApController::class, 'createArInvoice'])->middleware('permission:finance.ar-invoice.create');
    Route::post('ar/invoices/{arInvoice}/payments', [ArApController::class, 'payAr'])->middleware('permission:finance.payment.create');
    Route::post('ap/invoices/{supplierInvoice}/finalize-finance', [ArApController::class, 'finalizeAp'])->middleware('permission:finance.ap.create');
    Route::post('ap/invoices/{supplierInvoice}/payments', [ArApController::class, 'payAp'])->middleware('permission:finance.payment.create');
    Route::get('ar/aging', [ArApController::class, 'agingAr'])->middleware('permission:finance.report.view');
    Route::get('ap/aging', [ArApController::class, 'agingAp'])->middleware('permission:finance.report.view');
    Route::get('costing/mo/{productionOrder}/actual', [CostingController::class, 'actual'])->middleware('permission:costing.actual.view');
    Route::get('costing/mo/{productionOrder}/lineage', [CostingController::class, 'lineage'])->middleware('permission:costing.actual.view');
    Route::post('bep/style/{style}', [CostingController::class, 'bepStyle'])->middleware('permission:finance.bep.view');
    Route::post('bep/factory', [CostingController::class, 'bepFactory'])->middleware('permission:finance.bep.view');
});
