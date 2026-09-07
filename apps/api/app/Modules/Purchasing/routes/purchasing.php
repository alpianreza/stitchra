<?php

use Illuminate\Support\Facades\Route;
use Modules\Purchasing\Http\Controllers\PurchaseOrderController;
use Modules\Purchasing\Http\Controllers\PurchaseRequestController;
use Modules\Purchasing\Http\Controllers\SourcingController;
use Modules\Purchasing\Http\Controllers\SupplierInvoiceController;
use Modules\Receiving\Http\Controllers\GoodsReceiptController;
use Modules\Receiving\Http\Controllers\InwardInspectionController;
use Modules\Receiving\Http\Controllers\ReceivingLookupController;
use Modules\Receiving\Http\Controllers\ReceivingOperationsController;

Route::middleware(['auth:sanctum', 'company'])->group(function () {
    Route::get('purchasing/sourcing/options', [SourcingController::class, 'options'])->middleware('permission:purchasing.rfq.view');
    Route::get('purchasing/rfqs', [SourcingController::class, 'index'])->middleware('permission:purchasing.rfq.view');
    Route::post('purchasing/rfqs', [SourcingController::class, 'store'])->middleware('permission:purchasing.rfq.create');
    Route::get('purchasing/rfqs/{rfq}', [SourcingController::class, 'show'])->middleware('permission:purchasing.rfq.view');
    Route::post('purchasing/rfqs/{rfq}/quotations', [SourcingController::class, 'quotation'])->middleware('permission:purchasing.rfq.update');
    Route::post('purchasing/rfqs/{rfq}/close', [SourcingController::class, 'close'])->middleware('permission:purchasing.rfq.update');
    Route::post('purchasing/rfqs/{rfq}/quotations/{quotationId}/award', [SourcingController::class, 'award'])
        ->whereNumber('quotationId')->middleware(['permission:purchasing.rfq.update', 'permission:purchasing.po.create']);
    Route::get('purchasing/prs', [PurchaseRequestController::class, 'index'])->middleware('permission:purchasing.pr.view');
    Route::post('purchasing/prs', [PurchaseRequestController::class, 'store'])->middleware('permission:purchasing.pr.create');
    Route::post('purchasing/prs/{purchaseRequest}/submit', [PurchaseRequestController::class, 'submit'])->middleware('permission:purchasing.pr.submit');
    Route::get('purchasing/pos', [PurchaseOrderController::class, 'index'])->middleware('permission:purchasing.po.view');
    Route::post('purchasing/pos', [PurchaseOrderController::class, 'store'])->middleware('permission:purchasing.po.create');
    Route::get('purchasing/pos/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->middleware('permission:purchasing.po.view');
    Route::post('purchasing/pos/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit'])->middleware('permission:purchasing.po.submit');
    Route::post('purchasing/invoices', [SupplierInvoiceController::class, 'store'])->middleware('permission:purchasing.invoice.create');
    Route::post('purchasing/invoices/{supplierInvoice}/match', [SupplierInvoiceController::class, 'match'])->middleware('permission:purchasing.invoice.update');

    Route::get('receiving/grs', [GoodsReceiptController::class, 'index'])->middleware('permission:receiving.gr.view');
    Route::get('receiving/purchase-orders', [ReceivingLookupController::class, 'orders'])->middleware('permission:receiving.gr.create');
    Route::get('receiving/purchase-orders/{purchaseOrder}', [ReceivingLookupController::class, 'order'])->middleware('permission:receiving.gr.create');
    Route::get('receiving/warehouses', [ReceivingLookupController::class, 'warehouses'])->middleware('permission:receiving.gr.create');
    Route::get('receiving/locations', [ReceivingLookupController::class, 'locations'])->middleware('permission:receiving.gr.create');
    Route::get('receiving/inspection-grs', [GoodsReceiptController::class, 'index'])->middleware('permission:receiving.inspection.view');
    Route::get('receiving/inspection-grs/{goodsReceipt}', [GoodsReceiptController::class, 'show'])->middleware('permission:receiving.inspection.view');
    Route::get('receiving/inspection-defects', [ReceivingLookupController::class, 'defects'])->middleware('permission:receiving.inspection.view');
    Route::post('receiving/grs', [GoodsReceiptController::class, 'store'])->middleware('permission:receiving.gr.create');
    Route::get('receiving/grs/{goodsReceipt}', [GoodsReceiptController::class, 'show'])->middleware('permission:receiving.gr.view');
    Route::post('receiving/grs/{goodsReceipt}/inspections', [InwardInspectionController::class, 'store'])->middleware('permission:receiving.inspection.create');
    Route::post('receiving/inspections/{inwardInspection}/finalize', [InwardInspectionController::class, 'finalize'])->middleware('permission:receiving.inspection.update');

    Route::get('receiving/grs/{goodsReceipt}/stock-trace', [ReceivingOperationsController::class, 'trace'])->middleware('permission:receiving.gr.view');
    Route::post('receiving/grs/{goodsReceipt}/supplier-returns', [ReceivingOperationsController::class, 'storeReturn'])->middleware('permission:receiving.gr.create');
    Route::post('receiving/supplier-returns/{supplierReturn}/post', [ReceivingOperationsController::class, 'postReturn'])->middleware('permission:receiving.gr.submit');
    Route::post('receiving/supplier-returns/{supplierReturn}/cancel', [ReceivingOperationsController::class, 'cancelReturn'])->middleware('permission:receiving.gr.update');
    Route::post('receiving/grs/{goodsReceipt}/putaways', [ReceivingOperationsController::class, 'storePutaway'])->middleware('permission:receiving.gr.create');
    Route::post('receiving/putaways/{putaway}/post', [ReceivingOperationsController::class, 'postPutaway'])->middleware('permission:receiving.gr.submit');
    Route::post('receiving/putaways/{putaway}/cancel', [ReceivingOperationsController::class, 'cancelPutaway'])->middleware('permission:receiving.gr.update');
});
