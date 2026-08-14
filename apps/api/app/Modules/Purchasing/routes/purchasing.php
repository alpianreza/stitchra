<?php

use Illuminate\Support\Facades\Route;
use Modules\Purchasing\Http\Controllers\PurchaseOrderController;
use Modules\Purchasing\Http\Controllers\PurchaseRequestController;
use Modules\Purchasing\Http\Controllers\SupplierInvoiceController;
use Modules\Receiving\Http\Controllers\GoodsReceiptController;
use Modules\Receiving\Http\Controllers\InwardInspectionController;

Route::middleware(['auth:sanctum', 'company'])->group(function () {
    // Purchasing
    Route::post('purchasing/prs', [PurchaseRequestController::class, 'store']);
    Route::post('purchasing/prs/{purchaseRequest}/submit', [PurchaseRequestController::class, 'submit']);
    Route::get('purchasing/pos', [PurchaseOrderController::class, 'index']);
    Route::post('purchasing/pos', [PurchaseOrderController::class, 'store']);
    Route::post('purchasing/pos/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit']);
    Route::post('purchasing/invoices', [SupplierInvoiceController::class, 'store']);
    Route::post('purchasing/invoices/{supplierInvoice}/match', [SupplierInvoiceController::class, 'match']);

    // Receiving & Inward QC
    Route::get('receiving/grs', [GoodsReceiptController::class, 'index']);
    Route::post('receiving/grs', [GoodsReceiptController::class, 'store']);
    Route::get('receiving/grs/{goodsReceipt}', [GoodsReceiptController::class, 'show']);
    Route::post('receiving/grs/{goodsReceipt}/inspections', [InwardInspectionController::class, 'store']);
    Route::post('receiving/inspections/{inwardInspection}/finalize', [InwardInspectionController::class, 'finalize']);
});
