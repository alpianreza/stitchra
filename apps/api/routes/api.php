<?php

use Illuminate\Support\Facades\Route;

// Health check sederhana (tanpa auth) untuk monitoring on-prem.
Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'app' => config('app.name'),
    'time' => now()->toIso8601String(),
]));

// Modul Core (auth, rbac, approval, numbering, audit, settings) — diregistrasi
// oleh CoreServiceProvider di batch berikutnya.
