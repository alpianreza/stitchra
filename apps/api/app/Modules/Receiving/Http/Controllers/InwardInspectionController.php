<?php

namespace Modules\Receiving\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Core\Services\AuditService;
use Modules\Core\Support\CurrentCompany;
use Modules\Receiving\Models\GoodsReceipt;
use Modules\Receiving\Models\InwardInspection;
use Modules\Receiving\Services\InwardQcService;
use RuntimeException;

class InwardInspectionController extends Controller
{
    public function __construct(private InwardQcService $service, private AuditService $audit) {}

    public function store(Request $request, GoodsReceipt $goodsReceipt): JsonResponse
    {
        $companyId = CurrentCompany::id();
        $data = $request->validate([
            'lines' => 'required|array|min:1', 'lines.*.gr_line_id' => 'required|integer|exists:gr_lines,id',
            'lines.*.roll_id' => 'nullable|integer|exists:fabric_rolls,id',
            'lines.*.four_point_points' => 'nullable|numeric|min:0',
            'lines.*.shrinkage_pct_actual' => 'nullable|numeric', 'lines.*.gsm_actual' => 'nullable|numeric|gt:0',
            'lines.*.shade_verdict' => ['nullable', Rule::in(['MATCH', 'DEVIATION'])],
            'lines.*.defect_id' => ['nullable', 'integer', Rule::exists('defect_library', 'id')->where('company_id', $companyId)],
            'lines.*.result' => ['required', Rule::in(['PASS', 'FAIL'])], 'lines.*.notes' => 'nullable|string',
        ]);

        try {
            $inspection = $this->service->create($companyId, $goodsReceipt, $data['lines'], $request->user());
            $this->audit->record('create', $inspection, after: $inspection->toArray(), request: $request);
            return response()->json($inspection, 201);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function finalize(Request $request, InwardInspection $inwardInspection): JsonResponse
    {
        try {
            $this->service->finalize($inwardInspection, [], $request->user());
            $this->audit->record('finalize', $inwardInspection, request: $request);
            return response()->json($inwardInspection->fresh('lines'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
