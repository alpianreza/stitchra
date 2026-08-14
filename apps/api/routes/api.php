<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\AuthController;

// Health check sederhana (tanpa auth) untuk monitoring on-prem.
Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'app' => config('app.name'),
    'time' => now()->toIso8601String(),
]));

// Auth — BR-111
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum', 'company'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
});
