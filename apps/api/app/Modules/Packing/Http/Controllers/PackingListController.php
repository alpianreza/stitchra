<?php

namespace Modules\Packing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Packing\Models\PackingList;
use Modules\Packing\Services\PackingService;
use Modules\Sales\Models\SalesOrder;

class PackingListController extends Controller
{
    public function __construct(private PackingService $service) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('packing.packinglist.view'), 403);

        $query = PackingList::with('salesOrder.customer')->withCount('cartons');
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json($query->orderByDesc('id')->paginate(min((int) $request->query('per_page', 25), 100)));
    }

    public function store(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        abort_unless($request->user()->hasPermission('packing.packinglist.create'), 403);

        $data = $request->validate(['production_order_id' => 'nullable|integer|exists:production_orders,id']);

        try {
            $pl = $this->service->create($salesOrder, $data['production_order_id'] ?? null, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($pl, 201);
    }

    public function addCarton(Request $request, PackingList $packingList): JsonResponse
    {
        abort_unless($request->user()->hasPermission('packing.packinglist.update'), 403);

        $data = $request->validate([
            'carton.gross_weight_kg' => 'nullable|numeric|min:0',
            'carton.net_weight_kg' => 'nullable|numeric|min:0',
            'carton.dimension' => 'nullable|string|max:32',
            'lines' => 'required|array|min:1',
            'lines.*.style_id' => 'required|integer|exists:styles,id',
            'lines.*.colorway_id' => 'required|integer|exists:colorways,id',
            'lines.*.size_id' => 'required|integer|exists:sizes,id',
            'lines.*.qty' => 'required|numeric|min:0.0001',
        ]);

        try {
            $carton = $this->service->addCarton($packingList, $data['carton'] ?? [], $data['lines'], $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($carton, 201);
    }

    /** BR-082: finalize — wajib QC FINAL PASS + ratio check (BR-021); FG masuk gudang FG */
    public function finalize(Request $request, PackingList $packingList): JsonResponse
    {
        abort_unless($request->user()->hasPermission('packing.packinglist.submit'), 403);

        $data = $request->validate(['fg_warehouse_id' => 'required|integer|exists:warehouses,id']);

        try {
            $pl = $this->service->finalize($packingList, (int) $data['fg_warehouse_id'], $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($pl);
    }

    public function show(Request $request, PackingList $packingList): JsonResponse
    {
        abort_unless($request->user()->hasPermission('packing.packinglist.view'), 403);

        return response()->json($packingList->load('cartons.lines', 'salesOrder.customer'));
    }
}
