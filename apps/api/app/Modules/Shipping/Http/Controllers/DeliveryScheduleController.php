<?php

namespace Modules\Shipping\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Support\CurrentCompany;
use Modules\Sales\Models\SalesOrder;
use Modules\Shipping\Services\ShippingPlanService;
use RuntimeException;

class DeliveryScheduleController extends Controller
{
    public function __construct(private ShippingPlanService $service) {}
    public function index(Request $request): JsonResponse { return $this->domain(fn () => response()->json(['data' => $this->service->index(CurrentCompany::id(), $request->user())])); }
    public function salesOrders(Request $request): JsonResponse { return $this->domain(fn () => response()->json(['data' => $this->service->salesOrders(CurrentCompany::id(), $request->user())])); }
    public function store(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        $data = $request->validate(['delivery_date' => 'required|date', 'qty' => 'required|numeric|gt:0', 'destination' => 'nullable|string|max:255']);
        return $this->domain(fn () => response()->json($this->service->create($salesOrder, $data, $request->user()), 201));
    }
    private function domain(callable $callback): JsonResponse { try { return $callback(); } catch (RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); } }
}
