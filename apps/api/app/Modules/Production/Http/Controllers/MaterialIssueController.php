<?php

namespace Modules\Production\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Production\Models\MaterialIssue;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Services\MaterialIssueService;
use Modules\Receiving\Models\FabricRoll;

class MaterialIssueController extends Controller
{
    public function __construct(private MaterialIssueService $service) {}

    /** BR-041/060: issue aktual dari reservasi (fabric wajib per roll) */
    public function store(Request $request, ProductionOrder $productionOrder): JsonResponse
    {
        abort_unless($request->user()->hasPermission('production.issue.execute'), 403);

        $data = $request->validate([
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'lines' => 'required|array|min:1',
            'lines.*.material_id' => 'required|integer|exists:materials,id',
            'lines.*.qty' => 'required|numeric|min:0.0001',
            'lines.*.uom_id' => 'required|integer|exists:uoms,id',
            'lines.*.roll_id' => 'nullable|integer|exists:fabric_rolls,id',
            'lines.*.lot_no' => 'nullable|string|max:64',
        ]);

        try {
            $issue = $this->service->issue($productionOrder, (int) $data['warehouse_id'], $data['lines'], $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($issue, 201);
    }

    /** BR-041: backflush trim dari BOM × qty_produced */
    public function backflush(Request $request, ProductionOrder $productionOrder): JsonResponse
    {
        abort_unless($request->user()->hasPermission('production.issue.execute'), 403);

        $data = $request->validate(['warehouse_id' => 'required|integer|exists:warehouses,id']);

        $issue = $this->service->backflush($productionOrder, (int) $data['warehouse_id'], $request->user());

        return response()->json(['data' => $issue, 'message' => $issue ? 'Backflush diposting.' : 'Tidak ada material backflush di BOM.'], $issue ? 201 : 200);
    }

    /** BR-042: leftover roll kembali ke RM sebagai available */
    public function returnLeftover(Request $request, ProductionOrder $productionOrder, FabricRoll $roll): JsonResponse
    {
        abort_unless($request->user()->hasPermission('cutting.leftover.execute'), 403);

        $data = $request->validate([
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'reason' => 'nullable|string',
        ]);

        try {
            $return = $this->service->returnLeftover($productionOrder, $roll, (int) $data['warehouse_id'], $request->user(), $data['reason'] ?? null);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($return, 201);
    }

    public function index(Request $request, ProductionOrder $productionOrder): JsonResponse
    {
        abort_unless($request->user()->hasPermission('production.issue.view'), 403);

        return response()->json(
            MaterialIssue::where('production_order_id', $productionOrder->id)
                ->with('lines.material', 'lines.roll')
                ->orderByDesc('id')->get()
        );
    }
}
