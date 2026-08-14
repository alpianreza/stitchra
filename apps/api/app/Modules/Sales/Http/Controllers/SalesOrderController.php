<?php

namespace Modules\Sales\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\AuditService;
use Modules\Core\Support\CurrentCompany;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\SalesOrderService;

class SalesOrderController extends Controller
{
    public function __construct(
        private SalesOrderService $service,
        private AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('sales.order.view'), 403);

        $query = SalesOrder::with('customer')->withCount('lines');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json($query->orderByDesc('id')->paginate(min((int) $request->query('per_page', 25), 100)));
    }

    public function show(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        abort_unless($request->user()->hasPermission('sales.order.view'), 403);

        return response()->json($salesOrder->load(['lines.style', 'lines.colorway.color', 'lines.size', 'customer']));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('sales.order.create'), 403);

        $header = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'buyer_po_no' => 'nullable|string|max:64',
            'currency_id' => 'nullable|integer|exists:currencies,id',
            'order_date' => 'required|date',
            'ex_factory_date' => 'nullable|date',
            'tolerance_pct' => 'nullable|numeric|min:0|max:100',
        ]);

        $lines = $request->validate([
            'lines' => 'required|array|min:1',
            'lines.*.style_id' => 'required|integer|exists:styles,id',
            'lines.*.colorway_id' => 'required|integer|exists:colorways,id',
            'lines.*.size_id' => 'required|integer|exists:sizes,id',
            'lines.*.qty' => 'required|numeric|min:0.0001',
            'lines.*.price' => 'required|numeric|min:0',
        ])['lines'];

        $so = $this->service->create(CurrentCompany::id(), $header, $lines, $request->user());

        $this->audit->record('create', $so, after: $so->toArray(), request: $request);

        return response()->json($so, 201);
    }

    public function submit(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        abort_unless($request->user()->hasPermission('sales.order.submit'), 403);

        $this->service->submit($salesOrder, $request->user());

        $this->audit->record('submit', $salesOrder, request: $request);

        return response()->json($salesOrder->fresh());
    }

    /** BR-023: confirm gate — butuh BOM & Routing APPROVED untuk semua style */
    public function confirm(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        abort_unless($request->user()->hasPermission('sales.order.approve'), 403);

        $so = $this->service->confirm($salesOrder);

        $this->audit->record('confirm', $so, request: $request);

        return response()->json($so);
    }
}
