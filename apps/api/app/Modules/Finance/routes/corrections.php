<?php
use Illuminate\Support\Facades\Route;use Modules\Finance\Http\Controllers\AccountingCorrectionController;
Route::middleware(['auth:sanctum','company'])->prefix('finance/accounting-corrections')->group(function(){
 Route::get('journals/{journal}',[AccountingCorrectionController::class,'journal'])->middleware('permission:finance.report.view');
 Route::post('journals/{journal}',[AccountingCorrectionController::class,'request'])->middleware('permission:finance.journal.create');
 Route::get('{accountingCorrection}',[AccountingCorrectionController::class,'show'])->middleware('permission:finance.report.view');
 Route::get('{accountingCorrection}/lineage',[AccountingCorrectionController::class,'lineage'])->middleware('permission:finance.report.view');
 Route::post('{accountingCorrection}/approve',[AccountingCorrectionController::class,'approve'])->middleware('permission:finance.journal.approve');
 Route::post('{accountingCorrection}/reject',[AccountingCorrectionController::class,'reject'])->middleware('permission:finance.journal.approve');
 Route::post('{accountingCorrection}/post',[AccountingCorrectionController::class,'post'])->middleware('permission:finance.journal.approve');
});
