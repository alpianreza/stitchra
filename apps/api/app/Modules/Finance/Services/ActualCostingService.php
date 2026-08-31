<?php

namespace Modules\Finance\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\StockLedger;
use Modules\MasterData\Models\LineCostRate;
use Modules\MasterData\Models\OverheadRate;
use Modules\ProductDev\Models\CostSheet;
use Modules\Production\Models\ProductionOrder;
use RuntimeException;

class ActualCostingService
{
    public function computeForMo(ProductionOrder $mo,?string $period=null):array
    {
        $period=$period??($mo->created_at?->format('Y-m')??now()->format('Y-m'));if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$period))throw new RuntimeException('Period costing wajib format YYYY-MM.');
        $locked=ProductionOrder::withoutGlobalScopes()->with('routingVersion.operations')->where('company_id',$mo->company_id)->whereKey($mo->id)->firstOrFail();
        $lastOperationId=$locked->routingVersion?->operations?->sortByDesc('seq')->first()?->operation_id;
        $output=0.0;
        if($lastOperationId){$output=(float)DB::table('bundles')->where('bundles.company_id',$locked->company_id)->where('bundles.production_order_id',$locked->id)->whereExists(function($q)use($lastOperationId){$q->selectRaw('1')->from('production_scans')->whereColumn('production_scans.bundle_id','bundles.id')->where('production_scans.stage','SEWING')->where('production_scans.direction','OUT')->where('production_scans.operation_id',$lastOperationId);})->sum('bundles.qty');}
        if($output<=0)$output=(float)$locked->qty_produced;if($output<=0)throw new RuntimeException("MO {$locked->doc_no} belum punya output.");

        $ledger=StockLedger::withoutGlobalScopes()->join('material_issues','material_issues.id','=','stock_ledger.source_document_id')->where('stock_ledger.company_id',$locked->company_id)->where('stock_ledger.movement_type','MATERIAL_ISSUE')->where('stock_ledger.source_document_type','material_issues')->where('material_issues.production_order_id',$locked->id);
        if((clone $ledger)->whereNull('stock_ledger.unit_cost')->where('stock_ledger.qty_out','>',0)->exists())throw new RuntimeException('Historical unit cost belum tersedia pada material issue ledger.');
        $material=(float)(clone $ledger)->selectRaw('COALESCE(SUM(stock_ledger.qty_out * stock_ledger.unit_cost),0) cost')->value('cost');
        $sam=(float)($locked->routingVersion?->total_sam??0);if($sam<=0)throw new RuntimeException('Routing snapshot MO tidak memiliki total SAM valid.');
        if(!$locked->line_id)throw new RuntimeException('MO belum memiliki production line untuk labor costing.');
        $lineRate=LineCostRate::withoutGlobalScopes()->where('company_id',$locked->company_id)->where('line_id',$locked->line_id)->where('period',$period)->value('cost_per_minute');if($lineRate===null)throw new RuntimeException('Line cost rate belum dikonfigurasi untuk period ini.');
        $ohRate=OverheadRate::withoutGlobalScopes()->where('company_id',$locked->company_id)->where('period',$period)->value('rate_per_minute');if($ohRate===null)throw new RuntimeException('Overhead rate belum dikonfigurasi untuk period ini.');
        $labor=round($output*$sam*(float)$lineRate,4);$overhead=round($output*$sam*(float)$ohRate,4);
        $subcon=(float)DB::table('subcon_fees')->join('subcon_orders','subcon_orders.id','=','subcon_fees.subcon_order_id')->where('subcon_orders.company_id',$locked->company_id)->where('subcon_orders.production_order_id',$locked->id)->sum('subcon_fees.total_fee');$total=round($material+$labor+$overhead+$subcon,4);
        $standard=CostSheet::withoutGlobalScopes()->where('company_id',$locked->company_id)->where('style_id',$locked->style_id)->where('status','APPROVED')->latest('id')->first();$variance=null;
        if($standard){$sm=((float)$standard->fabric_cost+(float)$standard->trim_cost)*$output;$sl=(float)$standard->cm_cost*$output;$so=(float)$standard->overhead_cost*$output;$ss=(float)$standard->subcon_cost*$output;$st=$sm+$sl+$so+$ss;$variance=['material'=>round($material-$sm,4),'labor'=>round($labor-$sl,4),'overhead'=>round($overhead-$so,4),'subcon'=>round($subcon-$ss,4),'total'=>round($total-$st,4),'standard_total'=>round($st,4),'cost_sheet'=>$standard->doc_no];}
        return['mo'=>$locked->doc_no,'period'=>$period,'output_pcs'=>$output,'actual'=>['material'=>round($material,4),'labor'=>$labor,'overhead'=>$overhead,'subcon'=>round($subcon,4),'total'=>$total,'per_pcs'=>round($total/$output,4)],'variance_vs_standard'=>$variance];
    }
}
