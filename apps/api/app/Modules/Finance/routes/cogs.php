<?php
use Illuminate\Support\Facades\Route;use Modules\Finance\Http\Controllers\ShipmentCogsController;
Route::middleware(['auth:sanctum','company'])->prefix('finance')->group(function(){
 Route::get('cogs/shipments/{shipment}',[ShipmentCogsController::class,'shipment'])->middleware('permission:finance.report.view');
 Route::post('cogs/shipments/{shipment}/post',[ShipmentCogsController::class,'post'])->middleware('permission:finance.journal.create');
 Route::get('cogs/{shipmentCogs}',[ShipmentCogsController::class,'show'])->middleware('permission:finance.report.view');
 Route::get('cogs/{shipmentCogs}/lineage',[ShipmentCogsController::class,'lineage'])->middleware('permission:finance.report.view');
});
