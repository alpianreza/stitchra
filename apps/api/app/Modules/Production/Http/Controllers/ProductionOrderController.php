<?php

namespace Modules\Production\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Core\Support\CurrentCompany;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Services\ProductionOrderService;
use Modules\Sales\Models\SalesOrder;
use RuntimeException;

class ProductionOrderController extends Controller
{
    public function __construct(private ProductionOrderService $service) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(ProductionOrder::STATUSES)],
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $query = ProductionOrder::with('style', 'salesOrder', 'line');
        if (! empty($filters['status'])) $query->where('status', $filters['status']);
        return response()->json($query->orderByDesc('id')->paginate($filters['per_page'] ?? 25));
    }

    public function show(Request $request, ProductionOrder $productionOrder): JsonResponse
    {
        return response()->json($productionOrder->load([
            'style', 'salesOrder.customer', 'bomVersion.lines.material',
            'routingVersion.operations.operation', 'materialAllocations.material', 'line',
        ]));
    }

    public function createFromSo(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        return $this->domainResponse(function () use ($salesOrder, $request): JsonResponse {
            $mos = $this->service->createFromSalesOrder($salesOrder, $request->user());
            return response()->json(['data' => $mos, 'count' => count($mos)], 201);
        });
    }

    public function release(Request $request, ProductionOrder $productionOrder): JsonResponse
    {
        $companyId = CurrentCompany::id();
        $data = $request->validate([
            'warehouse_id' => ['required','integer',Rule::exists('warehouses','id')->where('company_id',$companyId)],
        ]);
        return $this->domainResponse(fn () => response()->json($this->service->release($productionOrder, (int) $data['warehouse_id'], $request->user())));
    }

    public function unrelease(Request $request, ProductionOrder $productionOrder): JsonResponse
    {
        return $this->domainResponse(fn () => response()->json($this->service->unrelease($productionOrder, $request->user())));
    }

    private function domainResponse(callable $callback): JsonResponse
    {
        try {
            return $callback();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
