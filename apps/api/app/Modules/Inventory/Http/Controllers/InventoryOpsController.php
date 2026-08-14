<?php

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Support\CurrentCompany;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockOpname;
use Modules\Inventory\Models\StockTransfer;
use Modules\Inventory\Services\InventoryOpsService;

class InventoryOpsController extends Controller
{
    public function __construct(private InventoryOpsService $service) {}

    /** Inquiry stok (saldo + available per baris) */
    public function stock(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('inventory.stock.view'), 403);

        $query = StockBalance::query();
        if ($materialId = $request->query('material_id')) {
            $query->where('material_id', $materialId);
        }
        if ($warehouseId = $request->query('warehouse_id')) {
            $query->where('warehouse_id', $warehouseId);
        }

        $rows = $query->orderBy('material_id')->limit(500)->get()->map(fn ($b) => [
            'material_id' => $b->material_id,
            'warehouse_id' => $b->warehouse_id,
            'lot_no' => $b->lot_no,
            'roll_id' => $b->roll_id,
            'ownership' => $b->ownership,
            'on_hand' => (float) $b->on_hand,
            'reserved' => (float) $b->reserved,
            'quality_hold' => (float) $b->quality_hold,
            'available' => $b->available(),   // BR-006
            'avg_cost' => $b->avg_cost !== null ? (float) $b->avg_cost : null,
        ]);

        return response()->json(['data' => $rows]);
    }

    public function createTransfer(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('inventory.transfer.create'), 403);

        $data = $request->validate([
            'from_warehouse_id' => 'required|integer|exists:warehouses,id',
            'to_warehouse_id' => 'required|integer|exists:warehouses,id|different:from_warehouse_id',
            'notes' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.material_id' => 'required|integer|exists:materials,id',
            'lines.*.qty' => 'required|numeric|min:0.0001',
            'lines.*.uom_id' => 'required|integer|exists:uoms,id',
            'lines.*.lot_no' => 'nullable|string|max:64',
            'lines.*.roll_id' => 'nullable|integer|exists:fabric_rolls,id',
        ]);

        try {
            $transfer = $this->service->createTransfer(CurrentCompany::id(), $data, $data['lines'], $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($transfer, 201);
    }

    public function postTransfer(Request $request, StockTransfer $stockTransfer): JsonResponse
    {
        abort_unless($request->user()->hasPermission('inventory.transfer.submit'), 403);

        try {
            $transfer = $this->service->postTransfer($stockTransfer, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($transfer);
    }

    public function receiveTransfer(Request $request, StockTransfer $stockTransfer): JsonResponse
    {
        abort_unless($request->user()->hasPermission('inventory.transfer.submit'), 403);

        try {
            $transfer = $this->service->receiveTransfer($stockTransfer, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($transfer);
    }

    public function createAdjustment(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('inventory.adjustment.create'), 403);

        $data = $request->validate([
            'reason' => 'required|string',
            'lines' => 'required|array|min:1',
            'lines.*.material_id' => 'required|integer|exists:materials,id',
            'lines.*.warehouse_id' => 'required|integer|exists:warehouses,id',
            'lines.*.qty_delta' => 'required|numeric|not_in:0',
            'lines.*.unit_cost' => 'nullable|numeric|min:0',
            'lines.*.uom_id' => 'required|integer|exists:uoms,id',
            'lines.*.lot_no' => 'nullable|string|max:64',
            'lines.*.roll_id' => 'nullable|integer|exists:fabric_rolls,id',
        ]);

        try {
            $adj = $this->service->createAdjustment(CurrentCompany::id(), $data['reason'], $data['lines'], $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($adj, 201);
    }

    /** BR-017: submit untuk approval — stok berubah HANYA setelah approved */
    public function submitAdjustment(Request $request, StockAdjustment $stockAdjustment): JsonResponse
    {
        abort_unless($request->user()->hasPermission('inventory.adjustment.submit'), 403);

        try {
            $this->service->submitAdjustment($stockAdjustment, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($stockAdjustment->fresh());
    }

    public function createOpname(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('inventory.opname.create'), 403);

        $data = $request->validate(['warehouse_id' => 'required|integer|exists:warehouses,id']);

        $opname = $this->service->createOpname(CurrentCompany::id(), (int) $data['warehouse_id'], $request->user());

        return response()->json($opname, 201);
    }

    public function recordCounts(Request $request, StockOpname $stockOpname): JsonResponse
    {
        abort_unless($request->user()->hasPermission('inventory.opname.submit'), 403);

        $data = $request->validate([
            'counts' => 'required|array|min:1',
            'counts.*.line_id' => 'required|integer|exists:stock_opname_lines,id',
            'counts.*.counted_qty' => 'required|numeric|min:0',
        ]);

        try {
            $opname = $this->service->recordCountsAndSubmit($stockOpname, $data['counts'], $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($opname);
    }
}
