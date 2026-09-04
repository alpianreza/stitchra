<?php

namespace Modules\Planning\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Core\Support\CurrentCompany;
use Modules\Planning\Models\CutPlan;
use Modules\Planning\Services\CutPlanService;
use Modules\Production\Models\ProductionOrder;
use RuntimeException;

class CutPlanController extends Controller
{
    public function __construct(private CutPlanService $service) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['per_page' => ['nullable', 'integer', 'between:1,100']]);
        return response()->json(CutPlan::with([
            'productionOrder.style', 'productionOrder.salesOrder',
            'lays.colorway.color', 'lays.ratios.size', 'cutOrders.lines',
        ])->orderByDesc('id')->paginate($filters['per_page'] ?? 25));
    }

    public function options(Request $request): JsonResponse
    {
        $mos = ProductionOrder::with([
            'style', 'salesOrder', 'matrixLines.colorway.color', 'matrixLines.size',
            'salesOrder.lines.colorway.color', 'salesOrder.lines.size',
        ])->where('status', 'RELEASED')->orderByDesc('id')->limit(100)->get();

        return response()->json(['production_orders' => $mos->map(function (ProductionOrder $mo): array {
            $persisted = $mo->matrixLines->isNotEmpty();
            $matrix = $persisted ? $mo->matrixLines : $mo->salesOrder->lines->where('style_id', $mo->style_id)->values();
            return [
                'id' => $mo->id,
                'doc_no' => $mo->doc_no,
                'sales_order_id' => $mo->sales_order_id,
                'style_id' => $mo->style_id,
                'qty_planned' => $mo->qty_planned,
                'style' => $mo->style,
                'sales_order' => $mo->salesOrder,
                'matrix_source' => $persisted ? 'MO_SNAPSHOT' : 'LEGACY_SO_FALLBACK',
                'matrix' => $matrix,
            ];
        })->values()]);
    }

    public function store(Request $request, ProductionOrder $productionOrder): JsonResponse
    {
        $companyId = CurrentCompany::id();
        $data = $request->validate([
            'lays' => ['required', 'array', 'min:1'],
            'lays.*.colorway_id' => ['required', 'integer', Rule::exists('colorways', 'id')->where('company_id', $companyId)],
            'lays.*.layer_count' => ['required', 'integer', 'min:1'],
            'lays.*.estimated_marker_length_m' => ['nullable', 'numeric', 'gt:0'],
            'lays.*.ratios' => ['required', 'array', 'min:1'],
            'lays.*.ratios.*.size_id' => ['required', 'integer', Rule::exists('sizes', 'id')->where('company_id', $companyId)],
            'lays.*.ratios.*.ratio_qty' => ['required', 'numeric', 'gt:0'],
        ]);
        return $this->domain(fn () => response()->json($this->service->create($productionOrder, $data['lays'], $request->user()), 201));
    }

    public function createCutOrder(Request $request, CutPlan $cutPlan): JsonResponse
    {
        return $this->domain(fn () => response()->json($this->service->createCutOrder($cutPlan, $request->user()), 201));
    }

    private function domain(callable $callback): JsonResponse
    {
        try { return $callback(); }
        catch (RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
    }
}
