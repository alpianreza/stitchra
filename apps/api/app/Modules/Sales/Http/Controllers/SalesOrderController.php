<?php

namespace Modules\Sales\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Core\Services\AuditService;
use Modules\Core\Support\CurrentCompany;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\SalesOrderService;
use RuntimeException;

class SalesOrderController extends Controller
{
    public function __construct(private SalesOrderService $service, private AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(SalesOrder::STATUSES)],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $query = SalesOrder::with(['customer', 'currency'])->withCount('lines');
        if (isset($filters['status'])) $query->where('status', $filters['status']);
        return response()->json($query->orderByDesc('id')->paginate($filters['per_page'] ?? 25));
    }

    public function show(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        return response()->json($salesOrder->load(['lines.style', 'lines.colorway.color', 'lines.size', 'customer', 'currency']));
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = CurrentCompany::id();
        $tenantExists = fn (string $table) => Rule::exists($table, 'id')->where('company_id', $companyId);
        $header = $request->validate([
            'customer_id' => ['required', 'integer', $tenantExists('customers')],
            'buyer_po_no' => ['nullable', 'string', 'max:64'],
            'currency_id' => ['nullable', 'integer', $tenantExists('currencies')],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'order_date' => ['required', 'date'],
            'ex_factory_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'tolerance_pct' => ['nullable', 'numeric', 'between:0,100'],
        ]);
        $lines = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.style_id' => ['required', 'integer', $tenantExists('styles')],
            'lines.*.colorway_id' => ['required', 'integer', $tenantExists('colorways')],
            'lines.*.size_id' => ['required', 'integer', $tenantExists('sizes')],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.price' => ['required', 'numeric', 'min:0'],
        ])['lines'];

        try { $so = $this->service->create($companyId, $header, $lines, $request->user()); }
        catch (RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }

        $this->audit->record('create', $so, after: $so->toArray(), request: $request);
        return response()->json($so, 201);
    }

    public function submit(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        try { $this->service->submit($salesOrder, $request->user()); }
        catch (RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
        $this->audit->record('submit', $salesOrder, request: $request);
        return response()->json($salesOrder->fresh());
    }

    public function confirm(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        try { $so = $this->service->confirm($salesOrder); }
        catch (RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
        $this->audit->record('confirm', $so, request: $request);
        return response()->json($so);
    }
}
