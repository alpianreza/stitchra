    /** Daftar roll (default: RELEASED dengan sisa) untuk pemilih roll saat issue (BR-041) */
    public function rolls(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('inventory.fabric-roll.view'), 403);

        $data = $request->validate([
            'material_id' => 'required|integer|exists:materials,id',
            'status' => 'nullable|in:QUALITY_HOLD,RELEASED,REJECTED_RETURNED,CONSUMED',
        ]);

        $rows = \Modules\Receiving\Models\FabricRoll::with('shadeGroup:id,code')
            ->where('material_id', $data['material_id'])
            ->where('status', $data['status'] ?? 'RELEASED')
            ->where('qty_remaining_meter', '>', 0)
            ->orderBy('roll_no')
            ->limit(200)
            ->get(['id', 'roll_no', 'lot_no', 'shade_group_id', 'qty_buy', 'qty_meter_actual', 'qty_remaining_meter', 'status']);

        return response()->json(['data' => $rows]);
    }

    public function createTransfer(Request $request): JsonResponse