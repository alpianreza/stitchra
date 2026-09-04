<?php

namespace Modules\Sales\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Sales\Models\OrderAmendment;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\OrderAmendmentService;
use RuntimeException;

class OrderAmendmentController extends Controller
{
    public function __construct(private OrderAmendmentService $service) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(OrderAmendment::with([
            'salesOrder.customer', 'lines.salesOrderLine.style', 'lines.salesOrderLine.colorway.color',
            'lines.salesOrderLine.size', 'deltaMrpRun.requirements.material',
        ])->orderByDesc('id')->paginate(min(100, max(1, (int) $request->input('per_page', 25)))));
    }

    public function store(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3'],
            'new_ex_factory_date' => ['nullable', 'date'],
            'lines' => ['nullable', 'array'],
            'lines.*.sales_order_line_id' => ['required', 'integer', 'distinct', 'exists:sales_order_lines,id'],
            'lines.*.new_qty' => ['required', 'numeric', 'gt:0'],
        ]);
        return $this->domain(fn () => response()->json($this->service->createDraft($salesOrder, $data, $request->user()), 201));
    }

    public function apply(Request $request, OrderAmendment $orderAmendment): JsonResponse
    {
        return $this->domain(fn () => response()->json($this->service->apply($orderAmendment, $request->user())));
    }

    private function domain(callable $callback): JsonResponse
    {
        try { return $callback(); }
        catch (RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
    }
}
