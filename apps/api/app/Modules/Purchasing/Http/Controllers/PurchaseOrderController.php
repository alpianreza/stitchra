<?php

namespace Modules\Purchasing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\AuditService;
use Modules\Core\Support\CurrentCompany;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Services\PurchasingService;

class PurchaseOrderController extends Controller
{
    public function __construct(private PurchasingService $service, private AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('purchasing.po.view'), 403);

        $query = PurchaseOrder::with('supplier');
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json($query->orderByDesc('id')->paginate(min((int) $request->query('per_page', 25), 100)));
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        abort_unless($request->user()->hasPermission('purchasing.po.view'), 403);

        return response()->json($purchaseOrder->load('lines.material', 'supplier'));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('purchasing.po.create'), 403);

        $data = $request->validate([
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'currency_id' => 'nullable|integer|exists:currencies,id',
            'order_date' => 'required|date',
            'expected_date' => 'nullable|date',
            'payment_term' => 'nullable|string|max:64',
            'lines' => 'required|array|min:1',
            'lines.*.material_id' => 'required|integer|exists:materials,id',
            'lines.*.qty' => 'required|numeric|min:0.0001',
            'lines.*.uom_id' => 'required|integer|exists:uoms,id',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.pr_line_id' => 'nullable|integer|exists:pr_lines,id',
        ]);

        $po = $this->service->createPo(CurrentCompany::id(), $data, $data['lines'], $request->user());
        $this->audit->record('create', $po, after: $po->toArray(), request: $request);

        return response()->json($po, 201);
    }

    public function submit(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        abort_unless($request->user()->hasPermission('purchasing.po.submit'), 403);

        $this->service->submitPo($purchaseOrder, $request->user());
        $this->audit->record('submit', $purchaseOrder, request: $request);

        return response()->json($purchaseOrder->fresh());
    }
}
