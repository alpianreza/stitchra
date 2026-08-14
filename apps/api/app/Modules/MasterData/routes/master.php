<?php

use Illuminate\Support\Facades\Route;
use Modules\MasterData\Http\Controllers\MasterDataController;

// Master data CRUD — BR-110 (permission di controller), BR-011 (company middleware)
Route::middleware(['auth:sanctum', 'company'])
    ->prefix('master')
    ->group(function () {
        Route::get('{entity}', [MasterDataController::class, 'index']);
        Route::post('{entity}', [MasterDataController::class, 'store']);
        Route::get('{entity}/{id}', [MasterDataController::class, 'show']);
        Route::put('{entity}/{id}', [MasterDataController::class, 'update']);
        Route::delete('{entity}/{id}', [MasterDataController::class, 'destroy']);
    });
