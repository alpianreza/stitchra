<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Support\CurrentCompany;
use Modules\Finance\Services\ValuationBoundaryService;
use Modules\Production\Models\ProductionOrder;
use Modules\Shipping\Models\Shipment;
use RuntimeException;

class ValuationBoundaryController extends Controller
{
    public function authority(Request $request, ValuationBoundaryService $service): JsonResponse
    {
        return $this->domain(fn () => response()->json($service->authorityMatrix(CurrentCompany::id(), $request->user())));
    }

    public function productionOrder(Request $request, ProductionOrder $productionOrder, ValuationBoundaryService $service): JsonResponse
    {
        return $this->domain(fn () => response()->json($service->productionOrderBoundary($productionOrder, $request->user())));
    }

    public function shipment(Request $request, Shipment $shipment, ValuationBoundaryService $service): JsonResponse
    {
        return $this->domain(fn () => response()->json($service->shipmentBoundary($shipment, $request->user())));
    }

    private function domain(callable $callback): JsonResponse
    {
        try { return $callback(); }
        catch (RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
    }
}
