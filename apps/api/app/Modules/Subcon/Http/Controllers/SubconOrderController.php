<?php

namespace Modules\Subcon\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Core\Support\CurrentCompany;
use Modules\Production\Models\ProductionOrder;
use Modules\Subcon\Models\SubconOrder;
use Modules\Subcon\Services\SubconService;
use RuntimeException;

class SubconOrderController extends Controller
{
    public function __construct(private SubconService $service) {}

    public function eligibleMaterials(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->eligibleMaterials(CurrentCompany::id(), $request->user())]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(SubconOrder::STATUSES)],
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $query = SubconOrder::with([
            'supplier', 'productionOrder.style', 'operation',
            'lines.material', 'lines.bundle', 'lines.uom', 'fees',
        ]);
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        return response()->json($query->orderByDesc('id')->paginate($data['per_page'] ?? 25));
    }

    public function store(Request $request, ProductionOrder $productionOrder): JsonResponse
    {
        $company = CurrentCompany::id();
        $data = $request->validate([
            'client_reference' => 'nullable|string|max:64',
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where(fn ($query) => $query->where('company_id', $company)->where('type', 'SUBCON')->where('is_active', true))],
            'operation_id' => ['nullable', 'integer', Rule::exists('operations', 'id')->where('company_id', $company)],
            'expected_return' => 'nullable|date',
            'fee_per_pcs' => 'required|numeric|min:0',
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('company_id', $company)->where('is_active', true)->where('type', '!=', 'SUBCON_VIRTUAL'))],
            'lines' => 'required|array|min:1',
            'lines.*.stock_balance_id' => ['nullable', 'integer', Rule::exists('stock_balances', 'id')->where(fn ($query) => $query->where('company_id', $company)->where('item_type', 'MATERIAL')->where('ownership', 'COMPANY'))],
            'lines.*.material_id' => ['nullable', 'integer', Rule::exists('materials', 'id')->where(fn ($query) => $query->where('company_id', $company)->where('is_active', true))],
            'lines.*.bundle_id' => ['nullable', 'integer', Rule::exists('bundles', 'id')->where('company_id', $company)],
            'lines.*.qty_sent' => 'required|numeric|gt:0',
            'lines.*.uom_id' => ['nullable', 'integer', Rule::exists('uoms', 'id')->where('company_id', $company)],
        ]);

        return $this->domain(fn () => response()->json(
            $this->service->createAndSend($company, $productionOrder, (int) $data['supplier_id'], $data['lines'], $data, $request->user()),
            201,
        ));
    }

    public function receive(Request $request, SubconOrder $subconOrder): JsonResponse
    {
        $company = CurrentCompany::id();
        $data = $request->validate([
            'returns' => 'required|array|min:1',
            'returns.*.line_id' => 'required|integer|exists:subcon_order_lines,id',
            'returns.*.qty_returned' => 'required|numeric|gt:0',
            'returns.*.receipt_reference' => 'nullable|string|max:64',
            'returns.*.warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('company_id', $company)->where('is_active', true)->where('type', '!=', 'SUBCON_VIRTUAL'))],
        ]);

        return $this->domain(fn () => response()->json(
            $this->service->receive($subconOrder, $data['returns'], $request->user()),
        ));
    }

    public function lineage(Request $request, SubconOrder $subconOrder): JsonResponse
    {
        return $this->domain(fn () => response()->json($this->service->lineage($subconOrder, $request->user())));
    }

    public function show(Request $request, SubconOrder $subconOrder): JsonResponse
    {
        return response()->json($subconOrder->load([
            'lines.material', 'lines.bundle', 'lines.uom',
            'fees.line', 'fees.warehouse', 'supplier', 'productionOrder.style', 'operation',
        ]));
    }

    private function domain(callable $callback): JsonResponse
    {
        try {
            return $callback();
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
