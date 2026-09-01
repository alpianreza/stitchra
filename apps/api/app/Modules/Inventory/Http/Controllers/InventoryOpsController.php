<?php

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Core\Support\CurrentCompany;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockOpname;
use Modules\Inventory\Models\StockTransfer;
use Modules\Inventory\Services\InventoryOpsService;
use Modules\Receiving\Models\FabricRoll;
use RuntimeException;

class InventoryOpsController extends Controller
{
    public function __construct(private InventoryOpsService $service) {}

    public function stock(Request $request): JsonResponse
    {
        $companyId = CurrentCompany::id();
        $filters = $request->validate([
            'material_id' => ['nullable', 'integer', Rule::exists('materials', 'id')->where('company_id', $companyId)],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
        ]);
        $query = StockBalance::with('material:id,code,name', 'warehouse:id,code,name');
        if (! empty($filters['material_id'])) $query->where('material_id', $filters['material_id']);
        if (! empty($filters['warehouse_id'])) $query->where('warehouse_id', $filters['warehouse_id']);

        $rows = $query->orderBy('material_id')->limit(500)->get()->map(fn ($balance) => [
            'material_id' => $balance->material_id, 'material_code' => $balance->material?->code,
            'material_name' => $balance->material?->name, 'warehouse_id' => $balance->warehouse_id,
            'warehouse_code' => $balance->warehouse?->code, 'lot_no' => $balance->lot_no,
            'roll_id' => $balance->roll_id, 'ownership' => $balance->ownership,
            'on_hand' => (float) $balance->on_hand, 'reserved' => (float) $balance->reserved,
            'quality_hold' => (float) $balance->quality_hold, 'available' => $balance->available(),
            'avg_cost' => $balance->avg_cost !== null ? (float) $balance->avg_cost : null,
        ]);
        return response()->json(['data' => $rows]);
    }

    public function rolls(Request $request): JsonResponse
    {
        $companyId = CurrentCompany::id();
        $data = $request->validate([
            'material_id' => ['required', 'integer', Rule::exists('materials', 'id')->where('company_id', $companyId)],
            'status' => ['nullable', Rule::in(['QUALITY_HOLD','RELEASED','REJECTED_RETURNED','CONSUMED'])],
        ]);
        $rows = FabricRoll::with('shadeGroup:id,code')
            ->where('material_id', $data['material_id'])->where('status', $data['status'] ?? 'RELEASED')
            ->where('qty_remaining_meter', '>', 0)->orderBy('roll_no')->limit(200)
            ->get(['id','roll_no','lot_no','shade_group_id','qty_buy','qty_meter_actual','qty_remaining_meter','status']);
        return response()->json(['data' => $rows]);
    }

    public function createTransfer(Request $request): JsonResponse
    {
        $companyId = CurrentCompany::id();
        $data = $request->validate([
            'from_warehouse_id' => ['required','integer',Rule::exists('warehouses','id')->where('company_id',$companyId)],
            'to_warehouse_id' => ['required','integer','different:from_warehouse_id',Rule::exists('warehouses','id')->where('company_id',$companyId)],
            'notes' => 'nullable|string', 'lines' => 'required|array|min:1',
            'lines.*.material_id' => ['required','integer',Rule::exists('materials','id')->where('company_id',$companyId)],
            'lines.*.qty' => 'required|numeric|min:0.0001',
            'lines.*.uom_id' => ['required','integer',Rule::exists('uoms','id')->where('company_id',$companyId)],
            'lines.*.lot_no' => 'nullable|string|max:64',
            'lines.*.roll_id' => ['nullable','integer',Rule::exists('fabric_rolls','id')->where('company_id',$companyId)],
        ]);
        return $this->domainResponse(fn () => response()->json($this->service->createTransfer($companyId, $data, $data['lines'], $request->user()), 201));
    }

    public function postTransfer(Request $request, StockTransfer $stockTransfer): JsonResponse
    {
        return $this->domainResponse(fn () => response()->json($this->service->postTransfer($stockTransfer, $request->user())));
    }

    public function receiveTransfer(Request $request, StockTransfer $stockTransfer): JsonResponse
    {
        return $this->domainResponse(fn () => response()->json($this->service->receiveTransfer($stockTransfer, $request->user())));
    }

    public function createAdjustment(Request $request): JsonResponse
    {
        $companyId = CurrentCompany::id();
        $data = $request->validate([
            'reason' => 'required|string', 'lines' => 'required|array|min:1',
            'lines.*.material_id' => ['required','integer',Rule::exists('materials','id')->where('company_id',$companyId)],
            'lines.*.warehouse_id' => ['required','integer',Rule::exists('warehouses','id')->where('company_id',$companyId)],
            'lines.*.qty_delta' => 'required|numeric|not_in:0', 'lines.*.unit_cost' => 'nullable|numeric|min:0',
            'lines.*.uom_id' => ['required','integer',Rule::exists('uoms','id')->where('company_id',$companyId)],
            'lines.*.lot_no' => 'nullable|string|max:64',
            'lines.*.roll_id' => ['nullable','integer',Rule::exists('fabric_rolls','id')->where('company_id',$companyId)],
        ]);
        return $this->domainResponse(fn () => response()->json($this->service->createAdjustment($companyId, $data['reason'], $data['lines'], $request->user()), 201));
    }

    public function submitAdjustment(Request $request, StockAdjustment $stockAdjustment): JsonResponse
    {
        return $this->domainResponse(function () use ($request, $stockAdjustment): JsonResponse {
            $this->service->submitAdjustment($stockAdjustment, $request->user());
            return response()->json($stockAdjustment->fresh());
        });
    }

    public function createOpname(Request $request): JsonResponse
    {
        $companyId = CurrentCompany::id();
        $data = $request->validate([
            'warehouse_id' => ['required','integer',Rule::exists('warehouses','id')->where('company_id',$companyId)],
        ]);
        return $this->domainResponse(fn () => response()->json($this->service->createOpname($companyId, (int) $data['warehouse_id'], $request->user()), 201));
    }

    public function recordCounts(Request $request, StockOpname $stockOpname): JsonResponse
    {
        $data = $request->validate([
            'counts' => 'required|array|min:1', 'counts.*.line_id' => 'required|integer|distinct|exists:stock_opname_lines,id',
            'counts.*.counted_qty' => 'required|numeric|min:0',
        ]);
        return $this->domainResponse(fn () => response()->json($this->service->recordCountsAndSubmit($stockOpname, $data['counts'], $request->user())));
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
