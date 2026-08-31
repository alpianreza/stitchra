<?php

use Illuminate\Support\Facades\Route;
use Modules\Purchasing\Http\Controllers\PurchaseOrderController;
use Modules\Purchasing\Http\Controllers\PurchaseRequestController;
use Modules\Purchasing\Http\Controllers\SupplierInvoiceController;
use Modules\Receiving\Http\Controllers\GoodsReceiptController;
use Modules\Receiving\Http\Controllers\InwardInspectionController;

Route::middleware(['auth:sanctum', 'company'])->group(function () {
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
    Route::post('receiving/grs', [GoodsReceiptController::class, 'store'])->middleware('permission:receiving.gr.create');
    Route::get('receiving/grs/{goodsReceipt}', [GoodsReceiptController::class, 'show'])->middleware('permission:receiving.gr.view');
    Route::post('receiving/grs/{goodsReceipt}/inspections', [InwardInspectionController::class, 'store'])->middleware('permission:receiving.inspection.create');
    Route::post('receiving/inspections/{inwardInspection}/finalize', [InwardInspectionController::class, 'finalize'])->middleware('permission:receiving.inspection.update');
});
