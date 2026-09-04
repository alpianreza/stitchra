<?php

namespace Modules\Planning\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Core\Support\CurrentCompany;
use Modules\MasterData\Models\Line;
use Modules\Planning\Models\LineLoading;
use Modules\Planning\Models\ProductionPlan;
use Modules\Planning\Services\ProductionPlanningService;
use Modules\Production\Models\ProductionOrder;
use Modules\Sales\Models\SalesOrder;
use RuntimeException;

class ProductionPlanningController extends Controller
{
    public function __construct(private ProductionPlanningService $service) {}

    public function options(Request $request): JsonResponse
    {
        return response()->json([
            'sales_orders' => SalesOrder::with(['customer', 'lines.style'])
                ->where('status', 'CONFIRMED')->orderByDesc('id')->limit(100)->get(),
            'lines' => Line::with('factory')->where('is_active', true)->orderBy('code')->get(),
            'production_orders' => ProductionOrder::with(['style', 'salesOrder'])
                ->where('status', 'PLANNED')->orderByDesc('id')->limit(200)->get(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = CurrentCompany::id();
        $filters = $request->validate([
            'line_id' => ['nullable', 'integer', Rule::exists('lines', 'id')->where('company_id', $companyId)],
            'sales_order_id' => ['nullable', 'integer', Rule::exists('sales_orders', 'id')->where('company_id', $companyId)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $query = ProductionPlan::with(['salesOrder.customer', 'style', 'line.factory', 'loadings.productionOrder'])
            ->withSum('loadings', 'planned_qty');
        if (! empty($filters['line_id'])) $query->where('line_id', $filters['line_id']);
        if (! empty($filters['sales_order_id'])) $query->where('sales_order_id', $filters['sales_order_id']);
        if (! empty($filters['from'])) $query->whereDate('period_end', '>=', $filters['from']);
        if (! empty($filters['to'])) $query->whereDate('period_start', '<=', $filters['to']);
        return response()->json($query->orderBy('period_start')->orderBy('line_id')->paginate($filters['per_page'] ?? 25));
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = CurrentCompany::id();
        $data = $request->validate([
            'sales_order_id' => ['required', 'integer', Rule::exists('sales_orders', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('status', 'CONFIRMED'))],
            'style_id' => ['required', 'integer', Rule::exists('styles', 'id')->where('company_id', $companyId)],
            'line_id' => ['required', 'integer', Rule::exists('lines', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('is_active', true))],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'target_qty' => ['required', 'numeric', 'gt:0'],
        ]);
        return $this->domainResponse(fn () => response()->json($this->service->createPlan($data, $request->user()), 201));
    }

    public function update(Request $request, ProductionPlan $productionPlan): JsonResponse
    {
        $companyId = CurrentCompany::id();
        $data = $request->validate([
            'line_id' => ['sometimes', 'integer', Rule::exists('lines', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('is_active', true))],
            'period_start' => ['sometimes', 'date'],
            'period_end' => ['sometimes', 'date'],
            'target_qty' => ['sometimes', 'numeric', 'gt:0'],
        ]);
        return $this->domainResponse(fn () => response()->json($this->service->updatePlan($productionPlan, $data, $request->user())));
    }

    public function storeLoading(Request $request, ProductionPlan $productionPlan): JsonResponse
    {
        $companyId = CurrentCompany::id();
        $data = $request->validate([
            'production_order_id' => ['required', 'integer', Rule::exists('production_orders', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('status', 'PLANNED'))],
            'plan_date' => ['required', 'date'],
            'planned_qty' => ['required', 'numeric', 'gt:0'],
        ]);
        return $this->domainResponse(fn () => response()->json($this->service->createLoading($productionPlan, $data, $request->user()), 201));
    }

    public function updateLoading(Request $request, LineLoading $lineLoading): JsonResponse
    {
        $data = $request->validate([
            'plan_date' => ['sometimes', 'date'],
            'planned_qty' => ['sometimes', 'numeric', 'gt:0'],
        ]);
        return $this->domainResponse(fn () => response()->json($this->service->updateLoading($lineLoading, $data, $request->user())));
    }

    public function capacity(Request $request): JsonResponse
    {
        $companyId = CurrentCompany::id();
        $filters = $request->validate([
            'line_id' => ['nullable', 'integer', Rule::exists('lines', 'id')->where('company_id', $companyId)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        return response()->json(['data' => $this->service->capacitySummary($filters)]);
    }

    private function domainResponse(callable $callback): JsonResponse
    {
        try {
            return $callback();
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
