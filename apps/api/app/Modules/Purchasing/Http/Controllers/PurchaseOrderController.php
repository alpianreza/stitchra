<?php

namespace Modules\Purchasing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Core\Services\AuditService;
use Modules\Core\Support\CurrentCompany;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Services\PurchasingService;
use RuntimeException;

class PurchaseOrderController extends Controller
{
    public function __construct(private PurchasingService $service, private AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(PurchaseOrder::STATUSES)],
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $query = PurchaseOrder::with('supplier');
        if (! empty($filters['status'])) $query->where('status', $filters['status']);
        return response()->json($query->orderByDesc('id')->paginate($filters['per_page'] ?? 25));
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        return response()->json($purchaseOrder->load('lines.material', 'supplier'));
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = CurrentCompany::id();
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'currency_id' => ['nullable', 'integer', Rule::exists('currencies', 'id')->where('company_id', $companyId)],
            'exchange_rate' => 'nullable|numeric|gt:0',
            'order_date' => 'required|date', 'expected_date' => 'nullable|date|after_or_equal:order_date',
            'payment_term' => 'nullable|string|max:64', 'lines' => 'required|array|min:1',
            'lines.*.material_id' => ['required', 'integer', Rule::exists('materials', 'id')->where('company_id', $companyId)],
            'lines.*.qty' => 'required|numeric|min:0.0001',
            'lines.*.uom_id' => ['required', 'integer', Rule::exists('uoms', 'id')->where('company_id', $companyId)],
            'lines.*.unit_price' => 'required|numeric|min:0', 'lines.*.pr_line_id' => 'nullable|integer|exists:pr_lines,id',
        ]);

        try {
            $po = $this->service->createPo($companyId, $data, $data['lines'], $request->user());
            $this->audit->record('create', $po, after: $po->toArray(), request: $request);
            return response()->json($po, 201);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function submit(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        try {
            $this->service->submitPo($purchaseOrder, $request->user());
            $this->audit->record('submit', $purchaseOrder, request: $request);
            return response()->json($purchaseOrder->fresh());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
