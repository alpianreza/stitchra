<?php

use Illuminate\Support\Facades\Route;
use Modules\Reporting\Http\Controllers\DashboardController;
use Modules\Reporting\Http\Controllers\ReportController;

Route::middleware(['auth:sanctum','company','throttle:60,1'])->group(function(){Route::get('reporting/reports',[ReportController::class,'index']);Route::get('reporting/reports/{report}',[ReportController::class,'run']);Route::get('reporting/reports/{report}/export',[ReportController::class,'export'])->middleware('throttle:10,1');Route::get('dashboard/kpis',[DashboardController::class,'kpis']);});
