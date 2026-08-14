<?php

namespace Modules\Purchasing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Core\Support\CurrentCompany;
use Modules\Purchasing\Models\SupplierInvoice;
use Modules\Purchasing\Services\ThreeWayMatchService;

class SupplierInvoiceController extends Controller
{
    public function __construct(
        private NumberingService $numbering,
        private ThreeWayMatchService $matcher,
        private AuditService $audit,
    ) {}

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('purchasing.invoice.create'), 403);

        $data = $request->validate([
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'purchase_order_id' => 'nullable|integer|exists:purchase_orders,id',
            'supplier_invoice_no' => 'nullable|string|max:64',
            'invoice_date' => 'required|date',
            'due_date' => 'nullable|date',
            'lines' => 'required|array|min:1',
            'lines.*.po_line_id' => 'nullable|integer|exists:po_lines,id',
            'lines.*.qty' => 'required|numeric|min:0.0001',
            'lines.*.unit_price' => 'required|numeric|min:0',
        ]);

        $companyId = CurrentCompany::id();
        $total = collect($data['lines'])->sum(fn ($l) => (float) $l['qty'] * (float) $l['unit_price']);

        $invoice = SupplierInvoice::create([
            'company_id' => $companyId,
            'doc_no' => $this->numbering->next($companyId, 'INV'),
            'supplier_id' => $data['supplier_id'],
            'purchase_order_id' => $data['purchase_order_id'] ?? null,
            'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null,
            'invoice_date' => $data['invoice_date'],
            'due_date' => $data['due_date'] ?? null,
            'total_amount' => round($total, 4),
            'status' => 'DRAFT',
            'created_by' => $request->user()->id,
        ]);

        foreach ($data['lines'] as $line) {
            $invoice->lines()->create([
                'po_line_id' => $line['po_line_id'] ?? null,
                'qty' => $line['qty'],
                'unit_price' => $line['unit_price'],
                'amount' => round((float) $line['qty'] * (float) $line['unit_price'], 4),
            ]);
        }

        $this->audit->record('create', $invoice, after: $invoice->toArray(), request: $request);

        return response()->json($invoice->load('lines'), 201);
    }

    /** BR-050: jalankan 3-way match */
    public function match(Request $request, SupplierInvoice $supplierInvoice): JsonResponse
    {
        abort_unless($request->user()->hasPermission('purchasing.invoice.match'), 403);

        $data = $request->validate([
            'price_tolerance_pct' => 'nullable|numeric|min:0|max:100',
            'qty_tolerance_pct' => 'nullable|numeric|min:0|max:100',
        ]);

        $matched = $this->matcher->match(
            $supplierInvoice,
            (float) ($data['price_tolerance_pct'] ?? 0),
            (float) ($data['qty_tolerance_pct'] ?? 0),
        );

        $this->audit->record('match', $matched, after: ['match_status' => $matched->match_status], request: $request);

        return response()->json($matched);
    }
}
