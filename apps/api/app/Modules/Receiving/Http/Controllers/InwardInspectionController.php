<?php

namespace Modules\Receiving\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\AuditService;
use Modules\Core\Support\CurrentCompany;
use Modules\Receiving\Models\GoodsReceipt;
use Modules\Receiving\Models\InwardInspection;
use Modules\Receiving\Services\InwardQcService;

class InwardInspectionController extends Controller
{
    public function __construct(private InwardQcService $service, private AuditService $audit) {}

    public function store(Request $request, GoodsReceipt $goodsReceipt): JsonResponse
    {
        abort_unless($request->user()->hasPermission('receiving.inspection.create'), 403);

        $data = $request->validate([
            'lines' => 'required|array|min:1',
            'lines.*.gr_line_id' => 'required|integer|exists:gr_lines,id',
            'lines.*.roll_id' => 'nullable|integer|exists:fabric_rolls,id',
            'lines.*.four_point_points' => 'nullable|numeric|min:0',
            'lines.*.shrinkage_pct_actual' => 'nullable|numeric',
            'lines.*.gsm_actual' => 'nullable|numeric|min:0',
            'lines.*.shade_verdict' => 'nullable|string|max:16',
            'lines.*.defect_id' => 'nullable|integer|exists:defect_library,id',
            'lines.*.result' => 'required|in:PASS,FAIL',
            'lines.*.notes' => 'nullable|string',
        ]);

        $inspection = $this->service->create(CurrentCompany::id(), $goodsReceipt, $data['lines'], $request->user());
        $this->audit->record('create', $inspection, after: $inspection->toArray(), request: $request);

        return response()->json($inspection, 201);
    }

    /** Finalisasi: PASS → release hold (BR-004); FAIL → tandai rejected */
    public function finalize(Request $request, InwardInspection $inwardInspection): JsonResponse
    {
        abort_unless($request->user()->hasPermission('receiving.inspection.finalize'), 403);

        $data = $request->validate([
            'lines' => 'required|array|min:1',
            'lines.*.gr_line_id' => 'required|integer|exists:gr_lines,id',
            'lines.*.roll_id' => 'nullable|integer|exists:fabric_rolls,id',
            'lines.*.result' => 'required|in:PASS,FAIL',
            'lines.*.material_id' => 'required|integer|exists:materials,id',
            'lines.*.warehouse_id' => 'required|integer|exists:warehouses,id',
            'lines.*.qty' => 'required|numeric|min:0.0001',
            'lines.*.uom_id' => 'required|integer|exists:uoms,id',
            'lines.*.lot_no' => 'nullable|string|max:64',
        ]);

        $this->service->finalize($inwardInspection, $data['lines'], $request->user());
        $this->audit->record('finalize', $inwardInspection, request: $request);

        return response()->json($inwardInspection->fresh('lines'));
    }
}
