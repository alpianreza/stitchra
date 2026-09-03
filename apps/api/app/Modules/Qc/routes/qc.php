<?php

use Illuminate\Support\Facades\Route;
use Modules\Packing\Http\Controllers\PackingListController;
use Modules\Qc\Http\Controllers\NcrController;
use Modules\Qc\Http\Controllers\QcInspectionController;
use Modules\Shipping\Http\Controllers\CommercialFulfillmentController;
use Modules\Shipping\Http\Controllers\ShipmentController;
use Modules\Subcon\Http\Controllers\SubconOrderController;

Route::middleware(['auth:sanctum', 'company'])->group(function () {
    Route::get('qc/mo/{productionOrder}/inspections', [QcInspectionController::class, 'index'])->middleware('permission:quality.inspection.view');
    Route::post('qc/mo/{productionOrder}/inspections', [QcInspectionController::class, 'store'])->middleware('permission:quality.inspection.create');
    Route::post('qc/inspections/{qcInspection}/defects', [QcInspectionController::class, 'recordDefects'])->middleware('permission:quality.defect.create');
    Route::post('qc/inspections/{qcInspection}/finalize', [QcInspectionController::class, 'finalize'])->middleware('permission:quality.inspection.submit');
    Route::post('qc/inspections/{qcInspection}/ncr', [NcrController::class, 'create'])->middleware('permission:quality.ncr.create');
    Route::get('qc/ncrs', [NcrController::class, 'index'])->middleware('permission:quality.ncr.view');
    Route::get('qc/ncrs/{ncr}', [NcrController::class, 'show'])->middleware('permission:quality.ncr.view');
    Route::post('qc/ncrs/{ncr}/dispositions', [NcrController::class, 'addDisposition'])->middleware('permission:quality.disposition.execute');
    Route::post('qc/ncrs/{ncr}/submit', [NcrController::class, 'submit'])->middleware('permission:quality.ncr.submit');

    Route::get('packing/eligible-inputs', [PackingListController::class, 'eligible'])->middleware('permission:packing.packinglist.view');
    Route::get('packing/lists', [PackingListController::class, 'index'])->middleware('permission:packing.packinglist.view');
    Route::post('packing/lists/from-so/{salesOrder}', [PackingListController::class, 'store'])->middleware('permission:packing.packinglist.create');
    Route::get('packing/lists/{packingList}/legacy-source-candidates', [PackingListController::class, 'legacySourceCandidates'])->middleware('permission:packing.packinglist.view');
    Route::post('packing/lists/{packingList}/source-attachments', [PackingListController::class, 'requestSourceAttachment'])->middleware('permission:packing.packinglist.update');
    Route::post('packing/source-attachments/{packingSourceAttachment}/apply', [PackingListController::class, 'applySourceAttachment'])->middleware('permission:packing.packinglist.update');
    Route::post('packing/lists/{packingList}/cartons', [PackingListController::class, 'addCarton'])->middleware('permission:packing.carton.create');
    Route::post('packing/lists/{packingList}/finalize', [PackingListController::class, 'finalize'])->middleware('permission:packing.packinglist.submit');
    Route::get('packing/lists/{packingList}/lineage', [PackingListController::class, 'lineage'])->middleware('permission:packing.packinglist.view');
    Route::get('packing/lists/{packingList}', [PackingListController::class, 'show'])->middleware('permission:packing.packinglist.view');

    Route::get('shipping/commercial-fulfillment/authority', [CommercialFulfillmentController::class, 'authority'])->middleware('permission:shipping.shipment.view');
    Route::get('shipping/commercial-fulfillment/sales-orders/{salesOrder}', [CommercialFulfillmentController::class, 'salesOrder'])->middleware('permission:shipping.shipment.view');
    Route::get('shipping/eligible-fg', [ShipmentController::class, 'eligibleFg'])->middleware('permission:shipping.shipment.view');
    Route::get('shipping/shipments', [ShipmentController::class, 'index'])->middleware('permission:shipping.shipment.view');
    Route::post('shipping/shipments/from-pl/{packingList}', [ShipmentController::class, 'store'])->middleware('permission:shipping.shipment.create');
    Route::post('shipping/shipments/{shipment}/approve-over-tolerance', [ShipmentController::class, 'approveOverTolerance'])->middleware('permission:shipping.shipment.submit');
    Route::post('shipping/shipments/{shipment}/ship', [ShipmentController::class, 'ship'])->middleware('permission:shipping.shipment.submit');
    Route::get('shipping/shipments/{shipment}/commercial-lineage', [CommercialFulfillmentController::class, 'shipment'])->middleware('permission:shipping.shipment.view');
    Route::get('shipping/shipments/{shipment}/lineage', [ShipmentController::class, 'lineage'])->middleware('permission:shipping.shipment.view');
    Route::get('shipping/shipments/{shipment}', [ShipmentController::class, 'show'])->middleware('permission:shipping.shipment.view');

    Route::get('subcon/eligible-materials', [SubconOrderController::class, 'eligibleMaterials'])->middleware('permission:subcon.jwo.view');
    Route::get('subcon/orders', [SubconOrderController::class, 'index'])->middleware('permission:subcon.jwo.view');
    Route::post('subcon/orders/from-mo/{productionOrder}', [SubconOrderController::class, 'store'])->middleware('permission:subcon.jwo.create');
    Route::post('subcon/orders/{subconOrder}/receive', [SubconOrderController::class, 'receive'])->middleware('permission:subcon.movement.create');
    Route::get('subcon/orders/{subconOrder}/lineage', [SubconOrderController::class, 'lineage'])->middleware('permission:subcon.jwo.view');
    Route::get('subcon/orders/{subconOrder}', [SubconOrderController::class, 'show'])->middleware('permission:subcon.jwo.view');
});
