<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\AuthController;

Route::get('/health',function(){try{DB::select('SELECT 1');return response()->json(['status'=>'ok','app'=>config('app.name'),'time'=>now()->toIso8601String()]);}catch(\Throwable){return response()->json(['status'=>'unavailable'],503);}});
Route::post('/auth/login',[AuthController::class,'login'])->middleware('throttle:login');
Route::middleware(['auth:sanctum','company'])->group(function(){Route::post('/auth/logout',[AuthController::class,'logout']);Route::get('/auth/me',[AuthController::class,'me']);});
