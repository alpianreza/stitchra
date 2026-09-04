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
        $query = ProductionOrder::with('style', 'salesOrder', 'line')->withCount('matrixLines');
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

    public function matrix(Request $request, ProductionOrder $productionOrder): JsonResponse
    {
        $matrix = $productionOrder->matrixLines()
            ->with(['colorway.color', 'size'])
            ->orderBy('colorway_id')->orderBy('size_id')->get();

        if ($matrix->isNotEmpty()) {
            return response()->json([
                'source' => 'MO_SNAPSHOT',
                'qty_planned' => $productionOrder->qty_planned,
                'matrix_total' => number_format((float) $matrix->sum('qty_planned'), 4, '.', ''),
                'data' => $matrix,
            ]);
        }

        $legacy = $productionOrder->salesOrder->lines()
            ->where('style_id', $productionOrder->style_id)
            ->with(['colorway.color', 'size'])
            ->orderBy('colorway_id')->orderBy('size_id')->get()
            ->map(fn ($line) => [
                'id' => null,
                'company_id' => $productionOrder->company_id,
                'production_order_id' => $productionOrder->id,
                'sales_order_line_id' => $line->id,
                'colorway_id' => $line->colorway_id,
                'size_id' => $line->size_id,
                'qty_planned' => $line->qty,
                'colorway' => $line->colorway,
                'size' => $line->size,
            ]);

        return response()->json([
            'source' => 'LEGACY_SO_FALLBACK',
            'qty_planned' => $productionOrder->qty_planned,
            'matrix_total' => number_format((float) $legacy->sum(fn ($line) => (float) $line['qty_planned']), 4, '.', ''),
            'data' => $legacy,
        ]);
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
