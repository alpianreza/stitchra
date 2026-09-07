<?php

namespace Modules\Receiving\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Receiving\Models\GoodsReceipt;
use Modules\Receiving\Models\Putaway;
use Modules\Receiving\Models\SupplierReturn;

class ReceivingTraceService
{
    public function __construct(private ReceiptStockService $stock) {}

    public function show(GoodsReceipt $gr, User $user): array
    {
        $this->stock->authorize((int) $gr->company_id, $user);
        $gr->load('lines.material', 'lines.rolls', 'purchaseOrder.supplier');
        $units = [];
        foreach ($gr->lines as $line) {
            foreach ($line->rolls->isEmpty() ? [null] : $line->rolls as $roll) {
                [, , $receipt] = $this->stock->resolve($gr, (int) $line->id, $roll?->id, false);
                $qc = $this->stock->qcResult($receipt);
                $returned = $this->stock->returnedQty($receipt);
                $reservedReturn = $this->stock->hasActiveReturn((int) $receipt->id);
                $allocated = $this->stock->allocatedPutawayQty((int) $receipt->id);
                $balances = DB::table('stock_balances as b')->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')
                    ->leftJoin('locations as l', 'l.id', '=', 'b.location_id')
                    ->where('b.company_id', $gr->company_id)->where('b.item_type', 'MATERIAL')
                    ->where('b.material_id', $receipt->material_id)->where('b.roll_id', $receipt->roll_id)
                    ->where('b.lot_no', $receipt->lot_no)->where('b.ownership', $receipt->ownership)
                    ->where('b.on_hand', '>', 0)
                    ->select('b.id', 'w.code as warehouse', 'l.code as location', 'b.on_hand', 'b.quality_hold', 'b.reserved')->get();
                $units[] = [
                    'receipt_ledger_id' => $receipt->id, 'gr_line_id' => $line->id,
                    'roll_id' => $roll?->id, 'roll_no' => $roll?->roll_no,
                    'material_id' => $receipt->material_id, 'material' => $line->material?->name,
                    'material_code' => $line->material?->code, 'qty' => $receipt->qty_in,
                    'uom_id' => $receipt->uom_id, 'uom' => DB::table('uoms')->where('id', $receipt->uom_id)->value('code'),
                    'location_id' => $receipt->location_id,
                    'location' => DB::table('locations')->where('id', $receipt->location_id)->value('code'),
                    'lot_no' => $receipt->lot_no, 'qc_result' => $qc, 'returned_qty' => $returned,
                    'putaway_allocated_qty' => $allocated,
                    'remaining_putaway_qty' => max(0.0, round((float) $receipt->qty_in - $allocated, 4)),
                    'can_return' => $qc === 'FAIL' && $returned === 0.0 && ! $reservedReturn && $allocated === 0.0,
                    'can_putaway' => $qc === 'PASS' && $returned === 0.0 && ! $reservedReturn && $allocated < (float) $receipt->qty_in,
                    'dimension_balances' => $balances,
                ];
            }
        }
        $returns = SupplierReturn::where('goods_receipt_id', $gr->id)->with('lines')->orderByDesc('id')->get();
        $returns->each(fn ($document) => $document->makeHidden('claim_amount'));
        $putaways = Putaway::where('goods_receipt_id', $gr->id)->with('lines.fromLocation', 'lines.toLocation')->orderByDesc('id')->get();
        $inspectionIds = DB::table('inward_inspections')->where('company_id', $gr->company_id)->where('goods_receipt_id', $gr->id)->pluck('id');
        $ledger = DB::table('stock_ledger as sl')
            ->leftJoin('locations as l', 'l.id', '=', 'sl.location_id')
            ->join('warehouses as w', 'w.id', '=', 'sl.warehouse_id')
            ->where('sl.company_id', $gr->company_id)->where(function ($query) use ($gr, $returns, $putaways, $inspectionIds) {
                foreach ([
                    'goods_receipts' => [$gr->id], 'supplier_returns' => $returns->modelKeys(),
                    'putaways' => $putaways->modelKeys(), 'inward_inspections' => $inspectionIds->all(),
                ] as $type => $ids) {
                    $query->orWhere(fn ($q) => $q->where('sl.source_document_type', $type)->whereIn('sl.source_document_id', $ids));
                }
            })->select('sl.id', 'sl.movement_type', 'sl.qty_in', 'sl.qty_out', 'sl.material_id', 'sl.roll_id', 'sl.lot_no',
                'sl.source_document_type', 'sl.source_document_id', 'sl.source_document_line_id',
                'sl.created_at', 'w.code as warehouse', 'l.code as location')
            ->orderByDesc('sl.id')->paginate(50);

        return [
            'gr' => $gr->only(['id', 'doc_no', 'status', 'warehouse_id']),
            'purchase_order' => $gr->purchaseOrder?->only(['id', 'doc_no', 'status']),
            'supplier' => $gr->purchaseOrder?->supplier?->only(['id', 'code', 'name']),
            'units' => $units, 'supplier_returns' => $returns, 'putaways' => $putaways, 'ledger' => $ledger,
            'locations' => DB::table('locations')->where('warehouse_id', $gr->warehouse_id)->orderBy('code')->get(['id', 'code', 'name']),
            'balance_note' => 'Saldo dimensi lot non-roll dapat mencakup receipt lain. Provenance per receipt berasal dari receipt ledger dan normalized operation lines, bukan dari pooled balance.',
        ];
    }
}
