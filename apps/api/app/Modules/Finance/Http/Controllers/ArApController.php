<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Support\CurrentCompany;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Services\ArApService;
use Modules\Purchasing\Models\SupplierInvoice;
use Modules\Shipping\Models\Shipment;

class ArApController extends Controller
{
    public function __construct(private ArApService $service) {}

    /** AR invoice dari shipment SHIPPED (harga SO, kurs BR-102; jurnal AUTO Piutang/Pendapatan) */
    public function createArInvoice(Request $request, Shipment $shipment): JsonResponse
    {
        abort_unless($request->user()->hasPermission('finance.ar.create'), 403);

        $data = $request->validate(['due_date' => 'nullable|date']);

        try {
            $invoice = $this->service->createArInvoiceFromShipment($shipment, $request->user(), $data['due_date'] ?? null);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($invoice, 201);
    }

    public function payAr(Request $request, ArInvoice $arInvoice): JsonResponse
    {
        abort_unless($request->user()->hasPermission('finance.ar.pay'), 403);

        $data = $request->validate([
            'amount' => 'required|numeric|min:0.0001',
            'payment_date' => 'nullable|date',
            'method' => 'nullable|string|max:32',
            'reference_no' => 'nullable|string|max:64',
        ]);

        try {
            $payment = $this->service->recordArPayment($arInvoice, $data, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($payment, 201);
    }

    /** BR-050: bayar supplier hanya untuk invoice MATCHED */
    public function payAp(Request $request, SupplierInvoice $supplierInvoice): JsonResponse
    {
        abort_unless($request->user()->hasPermission('finance.ap.pay'), 403);

        $data = $request->validate([
            'amount' => 'required|numeric|min:0.0001',
            'payment_date' => 'nullable|date',
            'method' => 'nullable|string|max:32',
            'reference_no' => 'nullable|string|max:64',
        ]);

        try {
            $payment = $this->service->recordApPayment($supplierInvoice, $data, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($payment, 201);
    }

    public function agingAr(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('finance.ar.view'), 403);

        return response()->json(['data' => $this->service->agingAr(CurrentCompany::id(), $request->query('as_of', now()->toDateString()))]);
    }

    public function agingAp(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('finance.ap.view'), 403);

        return response()->json(['data' => $this->service->agingAp(CurrentCompany::id(), $request->query('as_of', now()->toDateString()))]);
    }
}
