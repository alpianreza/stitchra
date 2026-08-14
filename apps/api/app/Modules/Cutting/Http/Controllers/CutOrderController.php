<?php

namespace Modules\Cutting\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Cutting\Models\CutOrder;
use Modules\Cutting\Services\CuttingService;
use Modules\Production\Models\ProductionOrder;

class CutOrderController extends Controller
{
    public function __construct(private CuttingService $service) {}

    public function store(Request $request, ProductionOrder $productionOrder): JsonResponse
    {
        abort_unless($request->user()->hasPermission('cutting.order.create'), 403);

        $data = $request->validate([
            'lines' => 'required|array|min:1',
            'lines.*.colorway_id' => 'required|integer|exists:colorways,id',
            'lines.*.size_id' => 'required|integer|exists:sizes,id',
            'lines.*.qty_cut' => 'required|numeric|min:0.0001',
        ]);

        try {
            $cutOrder = $this->service->create($productionOrder, $data['lines'], $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($cutOrder, 201);
    }

    /** BR-031/041: marker log — konsumsi aktual kain per roll */
    public function recordMarker(Request $request, CutOrder $cutOrder): JsonResponse
    {
        abort_unless($request->user()->hasPermission('cutting.marker.create'), 403);

        $data = $request->validate([
            'markers' => 'required|array|min:1',
            'markers.*.roll_id' => 'required|integer|exists:fabric_rolls,id',
            'markers.*.marker_length_m' => 'required|numeric|min:0',
            'markers.*.plies' => 'required|integer|min:1',
            'markers.*.qty_fabric_used_m' => 'required|numeric|min:0.0001',
            'markers.*.efficiency_pct' => 'nullable|numeric|min:0|max:100',
        ]);

        try {
            $cutOrder = $this->service->recordMarker($cutOrder, $data['markers'], $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($cutOrder);
    }

    /** BR-061: generate bundles dari cut order line */
    public function generateBundles(Request $request, CutOrder $cutOrder, int $line): JsonResponse
    {
        abort_unless($request->user()->hasPermission('cutting.bundle.create'), 403);

        $data = $request->validate(['bundle_size' => 'required|integer|min:1']);

        try {
            $bundles = $this->service->generateBundles($cutOrder, $line, (int) $data['bundle_size'], $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $bundles, 'count' => count($bundles)], 201);
    }

    /** Selesaikan cut order; update consumption_actual di BOM (BR-031) */
    public function complete(Request $request, CutOrder $cutOrder): JsonResponse
    {
        abort_unless($request->user()->hasPermission('cutting.order.complete'), 403);

        return response()->json($this->service->complete($cutOrder, $request->user()));
    }
}
