<?php

namespace Modules\Qc\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Production\Models\ProductionOrder;
use Modules\Qc\Models\QcInspection;
use Modules\Qc\Services\QcService;

class QcInspectionController extends Controller
{
    public function __construct(private QcService $service) {}

    public function index(Request $request, ProductionOrder $productionOrder): JsonResponse
    {
        abort_unless($request->user()->hasPermission('quality.inspection.view'), 403);

        return response()->json(
            QcInspection::where('production_order_id', $productionOrder->id)
                ->withCount('lines')->orderByDesc('id')->get()
        );
    }

    /** Buat inspeksi — FINAL menghitung sample size + Ac/Re otomatis (BR-008/071) */
    public function store(Request $request, ProductionOrder $productionOrder): JsonResponse
    {
        abort_unless($request->user()->hasPermission('quality.inspection.create'), 403);

        $data = $request->validate([
            'stage' => 'required|in:INLINE,ENDLINE,FINAL',
            'lot_qty' => 'required|numeric|min:1',
        ]);

        try {
            $inspection = $this->service->create($productionOrder, $data['stage'], (float) $data['lot_qty'], $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($inspection, 201);
    }

    /** BR-072: catat defect dari library */
    public function recordDefects(Request $request, QcInspection $qcInspection): JsonResponse
    {
        abort_unless($request->user()->hasPermission('quality.inspection.update'), 403);

        $data = $request->validate([
            'defects' => 'required|array|min:1',
            'defects.*.defect_id' => 'required|integer|exists:defect_library,id',
            'defects.*.qty' => 'nullable|integer|min:1',
            'defects.*.bundle_id' => 'nullable|integer|exists:bundles,id',
            'defects.*.operation_id' => 'nullable|integer|exists:operations,id',
            'defects.*.notes' => 'nullable|string',
        ]);

        try {
            $inspection = $this->service->recordDefects($qcInspection, $data['defects'], $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($inspection);
    }

    /** Finalisasi — FINAL: verdict otomatis AQL; INLINE/ENDLINE: manual PASS/FAIL (BR-073: FAIL → REWORK) */
    public function finalize(Request $request, QcInspection $qcInspection): JsonResponse
    {
        abort_unless($request->user()->hasPermission('quality.inspection.submit'), 403);

        $data = $request->validate(['verdict' => 'nullable|in:PASS,FAIL']);

        try {
            $inspection = $this->service->finalize($qcInspection, $request->user(), $data['verdict'] ?? null);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($inspection);
    }
}
