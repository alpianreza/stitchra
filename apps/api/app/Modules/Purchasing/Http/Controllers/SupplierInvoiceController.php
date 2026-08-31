<?php

namespace Modules\Purchasing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Core\Support\CurrentCompany;
use Modules\Purchasing\Models\PoLine;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Models\SupplierInvoice;
use Modules\Purchasing\Services\ThreeWayMatchService;
use RuntimeException;

class SupplierInvoiceController extends Controller
{
    public function __construct(
        private NumberingService $numbering,
        private ThreeWayMatchService $matcher,
        private AuditService $audit,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $companyId = CurrentCompany::id();
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'purchase_order_id' => ['required', 'integer', Rule::exists('purchase_orders', 'id')->where('company_id', $companyId)],
            'supplier_invoice_no' => 'nullable|string|max:64', 'invoice_date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:invoice_date', 'lines' => 'required|array|min:1',
            'lines.*.po_line_id' => 'required|integer|distinct|exists:po_lines,id',
            'lines.*.gr_line_id' => 'nullable|integer|exists:gr_lines,id',
            'lines.*.qty' => 'required|numeric|min:0.0001', 'lines.*.unit_price' => 'required|numeric|min:0',
        ]);

        try {
            $invoice = DB::transaction(function () use ($companyId, $data, $request): SupplierInvoice {
                $po = PurchaseOrder::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->whereKey($data['purchase_order_id'])
                    ->lockForUpdate()
                    ->firstOrFail();
                if ((int) $po->supplier_id !== (int) $data['supplier_id']) {
                    throw new RuntimeException('Supplier invoice harus sama dengan supplier PO.');
                }

                foreach ($data['lines'] as $line) {
                    $valid = PoLine::query()->where('purchase_order_id', $po->id)->whereKey($line['po_line_id'])->exists();
                    if (! $valid) {
                        throw new RuntimeException('Seluruh invoice line harus berasal dari PO yang dipilih.');
                    }
                }

                $total = collect($data['lines'])->sum(fn ($line) => (float) $line['qty'] * (float) $line['unit_price']);
                $invoice = SupplierInvoice::create([
                    'company_id' => $companyId,
                    'doc_no' => $this->numbering->next($companyId, 'INV'),
                    'supplier_id' => $data['supplier_id'], 'purchase_order_id' => $po->id,
                    'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null,
                    'invoice_date' => $data['invoice_date'], 'due_date' => $data['due_date'] ?? null,
                    'total_amount' => round($total, 4), 'status' => 'DRAFT',
                    'created_by' => $request->user()->id,
                ]);
                foreach ($data['lines'] as $line) {
                    $invoice->lines()->create([
                        'po_line_id' => $line['po_line_id'], 'gr_line_id' => $line['gr_line_id'] ?? null,
                        'qty' => $line['qty'], 'unit_price' => $line['unit_price'],
                        'amount' => round((float) $line['qty'] * (float) $line['unit_price'], 4),
                    ]);
                }
                return $invoice->load('lines');
            });

            $this->audit->record('create', $invoice, after: $invoice->toArray(), request: $request);
            return response()->json($invoice, 201);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function match(Request $request, SupplierInvoice $supplierInvoice): JsonResponse
    {
        try {
            $matched = $this->matcher->match(
                $supplierInvoice,
                (float) config('purchasing.price_tolerance_pct', 2.0),
                (float) config('purchasing.qty_tolerance_pct', 2.0),
            );
            $this->audit->record('match', $matched, after: ['match_status' => $matched->match_status], request: $request);
            return response()->json($matched);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
