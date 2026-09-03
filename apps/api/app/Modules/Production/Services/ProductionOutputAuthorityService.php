<?php

namespace Modules\Production\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Production\Models\ProductionOrder;
use RuntimeException;

/** BR-065 read-only named-measure view. It never writes qty_produced or creates an output ledger. */
class ProductionOutputAuthorityService
{
    public function __construct(private NamedProductionMeasureService $measures) {}
    public function inspect(ProductionOrder $productionOrder, User $user): array
    {
        return DB::transaction(function () use ($productionOrder,$user):array {
            $mo=ProductionOrder::withoutGlobalScopes()->whereKey($productionOrder->id)->firstOrFail();$this->access($user,(int)$mo->company_id);
            if(!DB::table('companies')->where('id',$mo->company_id)->where('is_active',true)->whereNull('deleted_at')->exists())throw new RuntimeException('Company Production tidak aktif.');
            $named=$this->measures->all($mo);
            $cut=DB::table('cut_outputs as output')->join('lays as lay','lay.id','=','output.lay_id')->join('cut_orders as co','co.id','=','lay.cut_order_id')->where('co.company_id',$mo->company_id)->where('co.production_order_id',$mo->id)->orderBy('output.id')->get(['output.id','output.lay_id','output.cut_order_line_id','output.qty_cut','lay.status as lay_status','co.status as cut_order_status']);
            $bundles=DB::table('bundles')->where('company_id',$mo->company_id)->where('production_order_id',$mo->id)->orderBy('id')->get(['id','bundle_no','cut_output_id','qty','current_stage','status']);
            $scans=DB::table('production_scans')->where('company_id',$mo->company_id)->where('production_order_id',$mo->id)->orderBy('id')->get(['id','bundle_id','operation_id','stage','direction','qty','scanned_at']);
            $qc=DB::table('qc_inspections')->where('company_id',$mo->company_id)->where('production_order_id',$mo->id)->where('stage','FINAL')->orderByDesc('cycle')->orderByDesc('id')->get(['id','doc_no','cycle','lot_qty','verdict','updated_at']);
            $packing=DB::table('packing_lists as p')->leftJoin('cartons as c','c.packing_list_id','=','p.id')->leftJoin('carton_lines as l','l.carton_id','=','c.id')->where('p.company_id',$mo->company_id)->where('p.production_order_id',$mo->id)->groupBy('p.id','p.doc_no','p.qc_inspection_id','p.status')->orderBy('p.id')->get(['p.id','p.doc_no','p.qc_inspection_id','p.status',DB::raw('COALESCE(SUM(l.qty),0) as packed_qty')]);
            $matrix=[
                $this->candidate($named['CUT_OUTPUT'],'Completed Lay/Cut Order','Cutting only','Creates Bundle'),
                ['measure_key'=>'BUNDLE_QTY','candidate_source'=>'Bundle Qty','quantity'=>round((float)$bundles->sum('qty'),4),'lifecycle'=>'ACTIVE/REWORK and current_stage','existing_authority'=>'Derived from Cut Output for new flow','downstream_usage'=>'Sewing/WIP','status'=>'DERIVED'],
                $this->candidate($named['SEWING_FINAL_OUT'],'Final routing operation OUT','Sewing only','Named downstream use'),
                $this->candidate($named['FINISHING_OUT'],'Finishing OUT scan','Finishing only','Named downstream use'),
                $this->candidate($named['QC_FINAL_PASS'],'Latest FINAL PASS cycle','Quality/Packing eligibility','Packing input'),
                $this->candidate($named['PACKED_QTY'],'APPROVED/SHIPPED Packing','Packing only','FG receipt source'),
                $this->candidate($named['FG_RECEIVED_QTY'],'ITS PRODUCTION_RECEIPT','FG receipt only','FG stock'),
                $this->candidate($named['SHIPPED_QTY'],'ITS SHIPMENT','Shipping only','Shipped quantity'),
            ];
            return ['production_order'=>['id'=>$mo->id,'doc_no'=>$mo->doc_no,'company_id'=>(int)$mo->company_id,'status'=>$mo->status,'qty_planned'=>(float)$mo->qty_planned,'actual_start'=>$mo->actual_start?->toDateString(),'actual_end'=>$mo->actual_end?->toDateString()],
                'production_output_authority'=>['status'=>'SEPARATE_NAMED_MEASURES','business_rule'=>'BR-065','authoritative_source'=>null,'authoritative_qty'=>null,'reason'=>'No generic whole-MO output. Every consumer must explicitly name its stage measure.'],
                'named_measures'=>$named,'qty_produced'=>['stored_value'=>(float)$mo->qty_produced,'status'=>'LEGACY','authoritative'=>false,'operational_writer'=>'NOT FOUND','write_endpoint'=>null,'warning'=>'LEGACY COMPATIBILITY — NOT AUTHORITY AND NOT A FALLBACK'],
                'production_completion'=>['status'=>'NO_GENERIC_COMPLETION','completion_endpoint'=>null,'completion_event'=>null,'explicit_completed_status'=>false,'current_status_progression'=>'Stage transitions remain separate from named quantities.','reason'=>'BR-065 creates no universal production-completion quantity.'],
                'candidate_matrix'=>$matrix,'quantity_evidence'=>['cut_outputs'=>$cut->map(fn($r)=>(array)$r)->values(),'bundles'=>$bundles->map(fn($r)=>(array)$r)->values(),'production_scans'=>$scans->map(fn($r)=>(array)$r)->values(),'qc_final'=>$qc->map(fn($r)=>(array)$r)->values(),'packing'=>$packing->map(fn($r)=>(array)$r)->values()],
                'partial_production'=>['status'=>'SUPPORTED_AS_SEPARATE_MEASURES','existing_behavior'=>'Each stage measure progresses independently.','reason'=>'No cross-stage arithmetic is inferred.'],
                'defect_rework_scrap'=>['status'=>'NOT DEFINED','reason'=>'BR-065 does not invent defect/rework/scrap arithmetic.'],
                'boundaries'=>['qc'=>'QC_FINAL_PASS is Packing eligibility only','packing'=>'PACKED_QTY is stage-scoped','fg'=>'FG_RECEIVED_QTY is FG quantity','shipment'=>'SHIPPED_QTY is shipped quantity','actual_cost'=>'EXPLICIT SOURCE NOT IMPLEMENTED IN BATCH 1','wip_valuation'=>'NOT IMPLEMENTED IN BATCH 1','cogs'=>'NOT IMPLEMENTED IN BATCH 1'],
                'downstream_consumers'=>[['consumer'=>'Actual Cost/per-unit','classification'=>'BLOCKED','use'=>'D-09 implementation outside Batch 1'],['consumer'=>'Backflush','classification'=>'DEFINED','use'=>'Configured one Named Stage per MO material'],['consumer'=>'Packing','classification'=>'DEFINED','use'=>'QC_FINAL_PASS and PACKED_QTY'],['consumer'=>'FG/Shipment','classification'=>'DEFINED','use'=>'FG_RECEIVED_QTY and SHIPPED_QTY']],
                'lineage'=>['forward'=>'MO → Cut Output → Final Sewing OUT → Finishing OUT → QC FINAL PASS → Packing Quantity → FG Received Quantity → Shipped Quantity','reverse'=>'Shipped → FG Receipt → Packing → QC → Finishing/Sewing → Cut Output → MO','authority_boundary'=>'BR-065: every measure keeps its stage scope; no universal qty is fabricated.'],
                'writes_performed'=>false,'migration'=>'2026_09_03_000028_lock_material_consumption_authority'];
        });
    }
    private function candidate(array $m,string $life,string $authority,string $usage):array{return['measure_key'=>$m['key'],'candidate_source'=>$m['label'],'quantity'=>$m['qty']??0.0,'lifecycle'=>$life,'existing_authority'=>$authority,'downstream_usage'=>$usage,'status'=>$m['status']];}
    private function access(User $u,int $company):void{if((int)$u->company_id!==$company&&!$u->companies()->whereKey($company)->exists())throw new RuntimeException('User tidak memiliki akses ke company Production.');}
}
