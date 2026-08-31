<?php

use Illuminate\Support\Facades\Route;
use Modules\Packing\Http\Controllers\PackingListController;
use Modules\Qc\Http\Controllers\QcInspectionController;
use Modules\Shipping\Http\Controllers\ShipmentController;
use Modules\Subcon\Http\Controllers\SubconOrderController;

Route::middleware(['auth:sanctum', 'company'])->group(function () {
    Route::get('qc/mo/{productionOrder}/inspections', [QcInspectionController::class, 'index'])->middleware('permission:quality.inspection.view');
    Route::post('qc/mo/{productionOrder}/inspections', [QcInspectionController::class, 'store'])->middleware('permission:quality.inspection.create');
    Route::post('qc/inspections/{qcInspection}/defects', [QcInspectionController::class, 'recordDefects'])->middleware('permission:quality.defect.create');
    Route::post('qc/inspections/{qcInspection}/finalize', [QcInspectionController::class, 'finalize'])->middleware('permission:quality.inspection.submit');

    Route::get('packing/lists', [PackingListController::class, 'index'])->middleware('permission:packing.packinglist.view');
    Route::post('packing/lists/from-so/{salesOrder}', [PackingListController::class, 'store'])->middleware('permission:packing.packinglist.create');
    Route::post('packing/lists/{packingList}/cartons', [PackingListController::class, 'addCarton'])->middleware('permission:packing.carton.create');
    Route::post('packing/lists/{packingList}/finalize', [PackingListController::class, 'finalize'])->middleware('permission:packing.packinglist.submit');
    Route::get('packing/lists/{packingList}', [PackingListController::class, 'show'])->middleware('permission:packing.packinglist.view');

    Route::get('shipping/shipments', [ShipmentController::class, 'index'])->middleware('permission:shipping.shipment.view');
    Route::post('shipping/shipments/from-pl/{packingList}', [ShipmentController::class, 'store'])->middleware('permission:shipping.shipment.create');
    Route::post('shipping/shipments/{shipment}/approve-over-tolerance', [ShipmentController::class, 'approveOverTolerance'])->middleware('permission:shipping.shipment.submit');
    Route::post('shipping/shipments/{shipment}/ship', [ShipmentController::class, 'ship'])->middleware('permission:shipping.shipment.submit');
    Route::get('shipping/shipments/{shipment}', [ShipmentController::class, 'show'])->middleware('permission:shipping.shipment.view');

    Route::get('subcon/orders', [SubconOrderController::class, 'index'])->middleware('permission:subcon.jwo.view');
    Route::post('subcon/orders/from-mo/{productionOrder}', [SubconOrderController::class, 'store'])->middleware('permission:subcon.jwo.create');
    Route::post('subcon/orders/{subconOrder}/receive', [SubconOrderController::class, 'receive'])->middleware('permission:subcon.movement.create');
    Route::get('subcon/orders/{subconOrder}', [SubconOrderController::class, 'show'])->middleware('permission:subcon.jwo.view');
});
