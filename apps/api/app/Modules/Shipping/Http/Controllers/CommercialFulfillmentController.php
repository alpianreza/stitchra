<?php

namespace Modules\Shipping\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Support\CurrentCompany;
use Modules\Sales\Models\SalesOrder;
use Modules\Shipping\Models\Shipment;
use Modules\Shipping\Services\CommercialFulfillmentService;
use RuntimeException;

class CommercialFulfillmentController extends Controller
{
    public function authority(Request $request, CommercialFulfillmentService $service): JsonResponse
    {
        return $this->domain(fn () => response()->json($service->authorityMatrix(CurrentCompany::id(), $request->user())));
    }

    public function salesOrder(Request $request, SalesOrder $salesOrder, CommercialFulfillmentService $service): JsonResponse
    {
        return $this->domain(fn () => response()->json($service->salesOrder($salesOrder, $request->user())));
    }

    public function shipment(Request $request, Shipment $shipment, CommercialFulfillmentService $service): JsonResponse
    {
        return $this->domain(fn () => response()->json($service->shipment($shipment, $request->user())));
    }

    private function domain(callable $callback): JsonResponse
    {
        try { return $callback(); }
        catch (RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
    }
}
