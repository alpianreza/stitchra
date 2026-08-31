<?php

namespace Modules\Cutting\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Core\Support\CurrentCompany;
use Modules\Cutting\Models\CutOrder;
use Modules\Cutting\Services\CuttingService;
use Modules\Production\Models\ProductionOrder;
use RuntimeException;

class CutOrderController extends Controller
{
    public function __construct(private CuttingService $service) {}

    public function store(Request $request, ProductionOrder $productionOrder): JsonResponse
    {
        $companyId = CurrentCompany::id();
        $data = $request->validate([
            'lines' => 'required|array|min:1',
            'lines.*.colorway_id' => ['required','integer',Rule::exists('colorways','id')->whereIn('style_id', [$productionOrder->style_id])],
            'lines.*.size_id' => ['required','integer',Rule::exists('sizes','id')->where('company_id',$companyId)],
            'lines.*.qty_cut' => 'required|numeric|min:0.0001',
        ]);
        return $this->domainResponse(fn () => response()->json($this->service->create($productionOrder, $data['lines'], $request->user()), 201));
    }

    public function recordMarker(Request $request, CutOrder $cutOrder): JsonResponse
    {
        $companyId = CurrentCompany::id();
        $data = $request->validate([
            'markers' => 'required|array|min:1',
            'markers.*.roll_id' => ['required','integer',Rule::exists('fabric_rolls','id')->where('company_id',$companyId)],
            'markers.*.marker_length_m' => 'required|numeric|gt:0', 'markers.*.plies' => 'required|integer|min:1',
            'markers.*.qty_fabric_used_m' => 'required|numeric|gt:0', 'markers.*.efficiency_pct' => 'nullable|numeric|min:0|max:100',
        ]);
        return $this->domainResponse(fn () => response()->json($this->service->recordMarker($cutOrder, $data['markers'], $request->user())));
    }

    public function generateBundles(Request $request, CutOrder $cutOrder, int $line): JsonResponse
    {
        $data = $request->validate(['bundle_size' => 'required|integer|min:1']);
        return $this->domainResponse(function () use ($cutOrder, $line, $data, $request): JsonResponse {
            $bundles = $this->service->generateBundles($cutOrder, $line, (int) $data['bundle_size'], $request->user());
            return response()->json(['data' => $bundles, 'count' => count($bundles)], 201);
        });
    }

    public function complete(Request $request, CutOrder $cutOrder): JsonResponse
    {
        return $this->domainResponse(fn () => response()->json($this->service->complete($cutOrder, $request->user())));
    }

    private function domainResponse(callable $callback): JsonResponse
    {
        try { return $callback(); }
        catch (RuntimeException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }
}
