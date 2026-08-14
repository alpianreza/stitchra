<?php

namespace Modules\Subcon\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Production\Models\ProductionOrder;
use Modules\Subcon\Models\SubconOrder;
use Modules\Subcon\Services\SubconService;

class SubconOrderController extends Controller
{
    public function __construct(private SubconService $service) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('subcon.order.view'), 403);

        $query = SubconOrder::with('supplier', 'productionOrder');
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json($query->orderByDesc('id')->paginate(min((int) $request->query('per_page', 25), 100)));
    }

    /** Buat + kirim subcon order (supplier wajib type SUBCON; bahan pendamping → SUBCON_OUT) */
    public function store(Request $request, ProductionOrder $productionOrder): JsonResponse
    {
        abort_unless($request->user()->hasPermission('subcon.order.create'), 403);

        $data = $request->validate([
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'operation_id' => 'nullable|integer|exists:operations,id',
            'expected_return' => 'nullable|date',
            'fee_per_pcs' => 'required|numeric|min:0',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'lines' => 'required|array|min:1',
            'lines.*.material_id' => 'nullable|integer|exists:materials,id',
            'lines.*.bundle_id' => 'nullable|integer|exists:bundles,id',
            'lines.*.qty_sent' => 'required|numeric|min:0.0001',
            'lines.*.uom_id' => 'nullable|integer|exists:uoms,id',
        ]);

        try {
            $order = $this->service->createAndSend(
                \Modules\Core\Support\CurrentCompany::id(),
                $productionOrder,
                (int) $data['supplier_id'],
                $data['lines'],
                $data,
                $request->user(),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($order, 201);
    }

    /** Terima hasil subcon — SUBCON_IN + fee (BR-091/080) */
    public function receive(Request $request, SubconOrder $subconOrder): JsonResponse
    {
        abort_unless($request->user()->hasPermission('subcon.order.receive'), 403);

        $data = $request->validate([
            'returns' => 'required|array|min:1',
            'returns.*.line_id' => 'required|integer|exists:subcon_order_lines,id',
            'returns.*.qty_returned' => 'required|numeric|min:0.0001',
            'returns.*.warehouse_id' => 'required|integer|exists:warehouses,id',
        ]);

        try {
            $order = $this->service->receive($subconOrder, $data['returns'], $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($order);
    }

    public function show(Request $request, SubconOrder $subconOrder): JsonResponse
    {
        abort_unless($request->user()->hasPermission('subcon.order.view'), 403);

        return response()->json($subconOrder->load('lines', 'fees', 'supplier'));
    }
}
