<?php

namespace Modules\Shipping\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Packing\Models\PackingList;
use Modules\Shipping\Models\Shipment;
use Modules\Shipping\Services\ShipmentService;

class ShipmentController extends Controller
{
    public function __construct(private ShipmentService $service) {}

    public function store(Request $request, PackingList $packingList): JsonResponse
    {
        abort_unless($request->user()->hasPermission('shipping.shipment.create'), 403);

        $data = $request->validate([
            'ship_date' => 'required|date',
            'forwarder' => 'nullable|string|max:255',
            'booking_no' => 'nullable|string|max:64',
            'container_no' => 'nullable|string|max:64',
            'vessel_flight' => 'nullable|string|max:64',
            'port_of_loading' => 'nullable|string|max:64',
            'port_of_discharge' => 'nullable|string|max:64',
        ]);

        try {
            $shipment = $this->service->create($packingList, $data, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($shipment, 201);
    }

    /** BR-021: approve shipment di luar toleransi (eksplisit + audit) */
    public function approveOverTolerance(Request $request, Shipment $shipment): JsonResponse
    {
        abort_unless($request->user()->hasPermission('shipping.shipment.approve'), 403);

        try {
            $shipment = $this->service->approveOverTolerance($shipment, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($shipment);
    }

    /** Kirim — FG keluar via ITS (BR-013); SO auto-close bila terpenuhi (BR-021) */
    public function ship(Request $request, Shipment $shipment): JsonResponse
    {
        abort_unless($request->user()->hasPermission('shipping.shipment.ship'), 403);

        $data = $request->validate(['fg_warehouse_id' => 'required|integer|exists:warehouses,id']);

        try {
            $shipment = $this->service->ship($shipment, (int) $data['fg_warehouse_id'], $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($shipment);
    }

    public function show(Request $request, Shipment $shipment): JsonResponse
    {
        abort_unless($request->user()->hasPermission('shipping.shipment.view'), 403);

        return response()->json($shipment->load('lines', 'salesOrder.customer', 'packingList.cartons'));
    }
}
