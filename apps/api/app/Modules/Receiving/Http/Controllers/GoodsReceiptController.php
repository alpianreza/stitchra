<?php

namespace Modules\Receiving\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Support\CurrentCompany;
use Modules\Receiving\Models\GoodsReceipt;
use Modules\Receiving\Services\ReceivingService;

class GoodsReceiptController extends Controller
{
    public function __construct(private ReceivingService $service) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('receiving.gr.view'), 403);

        return response()->json(
            GoodsReceipt::with('purchaseOrder.supplier')
                ->orderByDesc('id')
                ->paginate(min((int) $request->query('per_page', 25), 100))
        );
    }

    public function show(Request $request, GoodsReceipt $goodsReceipt): JsonResponse
    {
        abort_unless($request->user()->hasPermission('receiving.gr.view'), 403);

        return response()->json($goodsReceipt->load('lines.rolls', 'lines.poLine'));
    }

    /** Buat + posting GR — fabric wajib per roll (BR-052); stok masuk QUALITY_HOLD (BR-004) */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('receiving.gr.create'), 403);

        $data = $request->validate([
            'purchase_order_id' => 'required|integer|exists:purchase_orders,id',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'received_date' => 'required|date',
            'delivery_note_no' => 'nullable|string|max:64',
            'lines' => 'required|array|min:1',
            'lines.*.po_line_id' => 'required|integer|exists:po_lines,id',
            'lines.*.qty_received' => 'required|numeric|min:0.0001',
            'lines.*.uom_id' => 'required|integer|exists:uoms,id',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.rolls' => 'nullable|array',
            'lines.*.rolls.*.roll_no' => 'required|string|max:64',
            'lines.*.rolls.*.qty_buy' => 'required|numeric|min:0.0001',
            'lines.*.rolls.*.qty_meter_actual' => 'nullable|numeric|min:0',
            'lines.*.rolls.*.lot_no' => 'nullable|string|max:64',
            'lines.*.rolls.*.shade_group_id' => 'nullable|integer|exists:shade_groups,id',
            'lines.*.rolls.*.gsm_actual' => 'nullable|numeric|min:0',
            'lines.*.rolls.*.width_actual_cm' => 'nullable|numeric|min:0',
        ]);

        // Lengkapi material_id dari PO line (server-side, tidak percaya payload)
        $po = \Modules\Purchasing\Models\PurchaseOrder::findOrFail($data['purchase_order_id']);
        $lines = collect($data['lines'])->map(function ($l) use ($po) {
            $poLine = $po->lines()->findOrFail($l['po_line_id']);
            $l['material_id'] = $poLine->material_id;
            return $l;
        })->all();

        $gr = $this->service->createAndPost(CurrentCompany::id(), [
            'purchase_order_id' => $po->id,
            'warehouse_id' => $data['warehouse_id'],
            'received_date' => $data['received_date'],
            'delivery_note_no' => $data['delivery_note_no'] ?? null,
        ], $lines, $request->user());

        return response()->json($gr, 201);
    }
}
