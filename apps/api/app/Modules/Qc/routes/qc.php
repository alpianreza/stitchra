<?php

use Illuminate\Support\Facades\Route;
use Modules\Packing\Http\Controllers\PackingListController;
use Modules\Qc\Http\Controllers\QcInspectionController;
use Modules\Shipping\Http\Controllers\ShipmentController;
use Modules\Subcon\Http\Controllers\SubconOrderController;

Route::middleware(['auth:sanctum', 'company'])->group(function () {
    // QC — BR-008/070/071/072/073
    Route::get('qc/mo/{productionOrder}/inspections', [QcInspectionController::class, 'index']);
    Route::post('qc/mo/{productionOrder}/inspections', [QcInspectionController::class, 'store']);
    Route::post('qc/inspections/{qcInspection}/defects', [QcInspectionController::class, 'recordDefects']);
    Route::post('qc/inspections/{qcInspection}/finalize', [QcInspectionController::class, 'finalize']);

    // Packing — BR-021/082
    Route::post('packing/lists/from-so/{salesOrder}', [PackingListController::class, 'store']);
    Route::post('packing/lists/{packingList}/cartons', [PackingListController::class, 'addCarton']);
    Route::post('packing/lists/{packingList}/finalize', [PackingListController::class, 'finalize']);
    Route::get('packing/lists/{packingList}', [PackingListController::class, 'show']);

    // Shipment — BR-021
    Route::post('shipping/shipments/from-pl/{packingList}', [ShipmentController::class, 'store']);
    Route::post('shipping/shipments/{shipment}/approve-over-tolerance', [ShipmentController::class, 'approveOverTolerance']);
    Route::post('shipping/shipments/{shipment}/ship', [ShipmentController::class, 'ship']);
    Route::get('shipping/shipments/{shipment}', [ShipmentController::class, 'show']);

    // Subcon — BR-090/091/080
    Route::post('subcon/orders/from-mo/{productionOrder}', [SubconOrderController::class, 'store']);
    Route::post('subcon/orders/{subconOrder}/receive', [SubconOrderController::class, 'receive']);
    Route::get('subcon/orders/{subconOrder}', [SubconOrderController::class, 'show']);
});
