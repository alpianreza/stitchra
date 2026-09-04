<?php

namespace Modules\Production\Services;

use Illuminate\Support\Facades\DB;
use Modules\Production\Models\ProductionOrder;
use RuntimeException;

/** BR-065 named production measures. No generic qty_produced fallback is allowed. */
class NamedProductionMeasureService
{
    public const NAMED_MEASURES = [
        'CUT_OUTPUT', 'SEWING_FINAL_OUT', 'FINISHING_OUT', 'QC_FINAL_PASS',
        'PACKED_QTY', 'FG_RECEIVED_QTY', 'SHIPPED_QTY',
    ];
    public const BACKFLUSH_STAGES = self::NAMED_MEASURES;

    public function measure(ProductionOrder $productionOrder, string $key): array
    {
        $mo = ProductionOrder::withoutGlobalScopes()->where('company_id', $productionOrder->company_id)->whereKey($productionOrder->id)->firstOrFail();
        $key = strtoupper(trim($key));
        if (! in_array($key, self::NAMED_MEASURES, true)) throw new RuntimeException("BR-065/066: named stage {$key} tidak didukung.");

        return match ($key) {
            'CUT_OUTPUT' => $this->result($key, 'Cut Output', (float) DB::table('cut_outputs as output')
                ->join('lays as lay', 'lay.id', '=', 'output.lay_id')->join('cut_orders as cut', 'cut.id', '=', 'lay.cut_order_id')
                ->where('cut.company_id', $mo->company_id)->where('cut.production_order_id', $mo->id)
                ->where('lay.status', 'COMPLETED')->where('cut.status', '<>', 'CANCELLED')->sum('output.qty_cut'), 'BR-065:CUT_OUTPUT'),
            'SEWING_FINAL_OUT' => $this->sewingFinalOut($mo),
            'FINISHING_OUT' => $this->result($key, 'Finishing Output', (float) DB::table('finishing_outputs')
                ->where('company_id', $mo->company_id)->where('production_order_id', $mo->id)->sum('qty'), 'BR-065:FINISHING_OUT', 'DEFINED', ['source_table'=>'finishing_outputs','deduplication'=>'ONE_PER_BUNDLE']),
            'QC_FINAL_PASS' => $this->qcFinalPass($mo),
            'PACKED_QTY' => $this->result($key, 'Packing Quantity', (float) DB::table('packing_lists as packing')
                ->join('cartons', 'cartons.packing_list_id', '=', 'packing.id')->join('carton_lines', 'carton_lines.carton_id', '=', 'cartons.id')
                ->where('packing.company_id', $mo->company_id)->where('packing.production_order_id', $mo->id)
                ->whereIn('packing.status', ['APPROVED', 'SHIPPED'])->sum('carton_lines.qty'), 'BR-065:PACKED_QTY'),
            'FG_RECEIVED_QTY' => $this->result($key, 'FG Received Quantity', (float) DB::table('stock_ledger as ledger')
                ->join('packing_lists as packing', function ($join): void { $join->on('packing.id', '=', 'ledger.source_document_id')->where('ledger.source_document_type', '=', 'packing_lists'); })
                ->where('ledger.company_id', $mo->company_id)->where('packing.company_id', $mo->company_id)
                ->where('packing.production_order_id', $mo->id)->where('ledger.movement_type', 'PRODUCTION_RECEIPT')->sum('ledger.qty_in'), 'BR-065:FG_RECEIVED_QTY'),
            'SHIPPED_QTY' => $this->result($key, 'Shipped Quantity', (float) DB::table('stock_ledger as ledger')
                ->join('shipments as shipment', function ($join): void { $join->on('shipment.id', '=', 'ledger.source_document_id')->where('ledger.source_document_type', '=', 'shipments'); })
                ->join('packing_lists as packing', 'packing.id', '=', 'shipment.packing_list_id')
                ->where('ledger.company_id', $mo->company_id)->where('shipment.company_id', $mo->company_id)
                ->where('packing.production_order_id', $mo->id)->where('ledger.movement_type', 'SHIPMENT')->sum('ledger.qty_out'), 'BR-065:SHIPPED_QTY'),
        };
    }

    public function all(ProductionOrder $mo): array { return collect(self::NAMED_MEASURES)->mapWithKeys(fn (string $key) => [$key => $this->measure($mo, $key)])->all(); }

    private function sewingFinalOut(ProductionOrder $mo): array
    {
        $final = DB::table('routing_operations')->where('routing_version_id', $mo->routing_version_id)->orderByDesc('seq')->first(['operation_id', 'seq']);
        if ($final === null) return $this->result('SEWING_FINAL_OUT', 'Final Sewing OUT', null, 'BR-065:SEWING_FINAL_OUT', 'NOT_AVAILABLE');
        $qty = (float) DB::table('production_scans')->where('company_id', $mo->company_id)->where('production_order_id', $mo->id)
            ->where('stage', 'SEWING')->where('direction', 'OUT')->where('operation_id', $final->operation_id)->sum('qty');
        return $this->result('SEWING_FINAL_OUT', 'Final Sewing OUT', $qty, 'BR-065:SEWING_FINAL_OUT', 'DEFINED', ['operation_id'=>(int)$final->operation_id,'routing_seq'=>(int)$final->seq]);
    }

    private function qcFinalPass(ProductionOrder $mo): array
    {
        $latest = DB::table('qc_inspections')->where('company_id', $mo->company_id)->where('production_order_id', $mo->id)
            ->where('stage', 'FINAL')->orderByDesc('cycle')->orderByDesc('id')->first(['id', 'cycle', 'lot_qty', 'verdict']);
        if ($latest === null) return $this->result('QC_FINAL_PASS', 'QC FINAL PASS Lot Quantity', null, 'BR-065:QC_FINAL_PASS', 'NOT_AVAILABLE', ['reason'=>'NO_FINAL_CYCLE']);
        $source = ['qc_inspection_id'=>(int)$latest->id,'cycle'=>(int)$latest->cycle,'verdict'=>(string)$latest->verdict];
        if ($latest->verdict !== 'PASS') return $this->result('QC_FINAL_PASS', 'QC FINAL PASS Lot Quantity', null, 'BR-065:QC_FINAL_PASS', 'NOT_AVAILABLE', $source + ['reason'=>'LATEST_FINAL_CYCLE_NOT_PASS']);
        return $this->result('QC_FINAL_PASS', 'QC FINAL PASS Lot Quantity', (float)$latest->lot_qty, 'BR-065:QC_FINAL_PASS', 'DEFINED', $source);
    }

    private function result(string $key, string $label, ?float $qty, string $authority, string $status='DEFINED', array $source=[]): array
    { return ['key'=>$key,'label'=>$label,'qty'=>$qty===null?null:round($qty,4),'status'=>$status,'authority'=>$authority,'source'=>$source]; }
}
