<?php

namespace Modules\Production\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\AuditService;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Services\ProductionOrderService;
use Modules\Sales\Models\SalesOrder;

class ProductionOrderController extends Controller
{
    public function __construct(private ProductionOrderService $service, private AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('production.mo.view'), 403);

        $query = ProductionOrder::with('style', 'salesOrder', 'line');
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json($query->orderByDesc('id')->paginate(min((int) $request->query('per_page', 25), 100)));
    }

    public function show(Request $request, ProductionOrder $productionOrder): JsonResponse
    {
        abort_unless($request->user()->hasPermission('production.mo.view'), 403);

        return response()->json($productionOrder->load([
            'style', 'salesOrder.customer', 'bomVersion.lines.material', 'routingVersion.operations.operation',
            'materialAllocations.material', 'line',
        ]));
    }

    /** Generate MO dari SO CONFIRMED (satu per style; snapshot BOM/Routing — BR-030) */
    public function createFromSo(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        abort_unless($request->user()->hasPermission('production.mo.create'), 403);

        $mos = $this->service->createFromSalesOrder($salesOrder, $request->user());

        return response()->json(['data' => $mos, 'count' => count($mos)], 201);
    }

    /** BR-060: release = hard reservation (atomic; shortage → 422 + daftar kurang) */
    public function release(Request $request, ProductionOrder $productionOrder): JsonResponse
    {
        abort_unless($request->user()->hasPermission('production.mo.release'), 403);

        $data = $request->validate(['warehouse_id' => 'required|integer|exists:warehouses,id']);

        try {
            $mo = $this->service->release($productionOrder, (int) $data['warehouse_id'], $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($mo);
    }

    public function unrelease(Request $request, ProductionOrder $productionOrder): JsonResponse
    {
        abort_unless($request->user()->hasPermission('production.mo.release'), 403);

        return response()->json($this->service->unrelease($productionOrder, $request->user()));
    }
}
