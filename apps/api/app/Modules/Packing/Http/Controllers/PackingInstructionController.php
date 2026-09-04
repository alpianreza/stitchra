<?php

namespace Modules\Packing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Core\Support\CurrentCompany;
use Modules\Packing\Models\PackingInstruction;
use Modules\Packing\Models\PackingList;
use Modules\Packing\Services\PackingInstructionService;
use Modules\Sales\Models\SalesOrder;
use RuntimeException;

class PackingInstructionController extends Controller
{
    public function __construct(private PackingInstructionService $service) {}

    public function candidates(): JsonResponse { return response()->json(['data' => $this->service->candidates(CurrentCompany::id())]); }

    public function show(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        return $this->domain(fn () => response()->json($this->service->activeForSalesOrder($salesOrder, $request->user())));
    }

    public function store(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        $company = CurrentCompany::id();
        $data = $request->validate([
            'pack_type' => ['required', Rule::in(PackingInstruction::TYPES)], 'lines' => 'required|array|min:1',
            'lines.*.style_id' => ['required', 'integer', Rule::exists('styles', 'id')->where('company_id', $company)],
            'lines.*.colorway_id' => 'required|integer|exists:colorways,id',
            'lines.*.size_id' => ['required', 'integer', Rule::exists('sizes', 'id')->where('company_id', $company)],
            'lines.*.ratio_qty' => 'required|integer|min:1',
        ]);
        return $this->domain(fn () => response()->json($this->service->createVersion($salesOrder, $data['pack_type'], $data['lines'], $request->user()), 201));
    }

    public function createList(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        $company = CurrentCompany::id();
        $data = $request->validate(['production_order_id' => ['required', 'integer', Rule::exists('production_orders', 'id')->where('company_id', $company)]]);
        return $this->domain(fn () => response()->json($this->service->createPackingList($salesOrder, (int) $data['production_order_id'], $request->user()), 201));
    }

    public function addCarton(Request $request, PackingList $packingList): JsonResponse
    {
        $company = CurrentCompany::id();
        $data = $request->validate([
            'carton.gross_weight_kg' => 'nullable|numeric|min:0', 'carton.net_weight_kg' => 'nullable|numeric|min:0',
            'carton.dimension' => 'nullable|string|max:32', 'lines' => 'required|array|min:1',
            'lines.*.style_id' => ['required', 'integer', Rule::exists('styles', 'id')->where('company_id', $company)],
            'lines.*.colorway_id' => 'required|integer|exists:colorways,id',
            'lines.*.size_id' => ['required', 'integer', Rule::exists('sizes', 'id')->where('company_id', $company)],
            'lines.*.qty' => 'required|numeric|gt:0',
        ]);
        return $this->domain(fn () => response()->json($this->service->addCarton($packingList, $data['carton'] ?? [], $data['lines'], $request->user()), 201));
    }

    public function finalize(Request $request, PackingList $packingList): JsonResponse
    {
        $company = CurrentCompany::id();
        $data = $request->validate(['fg_warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('company_id', $company)->where('type', 'FG')->where('is_active', true))]]);
        return $this->domain(fn () => response()->json($this->service->finalize($packingList, (int) $data['fg_warehouse_id'], $request->user())));
    }

    private function domain(callable $callback): JsonResponse
    {
        try { return $callback(); }
        catch (RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
    }
}
