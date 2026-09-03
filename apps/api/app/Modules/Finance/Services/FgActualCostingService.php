<?php

namespace Modules\Finance\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Approval\ApprovalEngine;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Finance\Models\ActualCostFreeze;
use Modules\Finance\Models\FgActualCosting;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Services\StandardCostSnapshotService;
use RuntimeException;

class FgActualCostingService
{
    public const CALCULATION_VERSION='D09_V1';
    public const COMPONENTS=['FABRIC','TRIM','LABOR','OVERHEAD','SUBCON','OTHER'];

    public function __construct(
        private StandardCostSnapshotService $snapshots,
        private ApprovalEngine $approval,
        private AuditService $audit,
        private ManufacturingValuationService $valuation,
    ) {}

    public static function costPerPcs(float $total,float $denominator): float
    {
        if($denominator<=0)throw new RuntimeException('FAIL_CLOSED: FG received denominator must be greater than zero.');
        return round($total/$denominator,4);
    }

    public function calculate(ProductionOrder $mo,string $period,User $user): FgActualCosting
    {
        if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$period))throw new RuntimeException('Period costing must use YYYY-MM.');
        return DB::transaction(function()use($mo,$period,$user):FgActualCosting{
            $locked=ProductionOrder::withoutGlobalScopes()->whereKey($mo->id)->lockForUpdate()->firstOrFail();
            $this->access($user,(int)$locked->company_id);
            $eligibility=DB::table('mo_valuation_eligibilities')->where('company_id',$locked->company_id)->where('production_order_id',$locked->id)
                ->where('policy_version','D06_D07_V1')->where('status','APPROVED')->lockForUpdate()->first();
            if(!$eligibility)throw new RuntimeException('FAIL_CLOSED: prospective D-06/D-07 eligibility is not approved.');
            $this->snapshots->verify($locked);
            $candidate=$this->candidate($locked,$period);
            $blocked=collect($candidate['completeness'])->filter(fn($s)=>in_array($s,['MISSING','CONFLICT'],true));
            if($blocked->isNotEmpty())throw new RuntimeException('FAIL_CLOSED: actual-cost completeness '.json_encode($candidate['completeness'],JSON_THROW_ON_ERROR));
            $sourceHash=$this->hash(['calculation_version'=>self::CALCULATION_VERSION,'standard_hash'=>$locked->standard_cost_snapshot_hash,
                'period'=>$period,'denominator'=>$candidate['denominator'],'components'=>$candidate['components'],'evidence'=>$candidate['evidence'],'provisional'=>$candidate['provisional']]);
            $existing=FgActualCosting::withoutGlobalScopes()->with(['components','freeze'])->where('company_id',$locked->company_id)
                ->where('production_order_id',$locked->id)->where('valuation_object','FG_ACTUAL')->where('source_hash',$sourceHash)->lockForUpdate()->first();
            if($existing)return $existing;
            $version=(int)FgActualCosting::withoutGlobalScopes()->where('company_id',$locked->company_id)->where('production_order_id',$locked->id)->lockForUpdate()->max('costing_version')+1;
            $freezeVersion=(int)ActualCostFreeze::withoutGlobalScopes()->where('company_id',$locked->company_id)->where('production_order_id',$locked->id)->lockForUpdate()->max('freeze_version')+1;
            $actualTotal=round(array_sum($candidate['components']),4);
            $perPcs=self::costPerPcs($actualTotal,$candidate['denominator']);
            $calculationHash=$this->hash(['source_hash'=>$sourceHash,'costing_version'=>$version,'actual_total'=>$actualTotal,'actual_per_pcs'=>$perPcs]);
            $freeze=ActualCostFreeze::create(['company_id'=>$locked->company_id,'production_order_id'=>$locked->id,'eligibility_id'=>$eligibility->id,
                'freeze_version'=>$freezeVersion,'status'=>'PENDING','period'=>$period,'standard_snapshot_hash'=>$locked->standard_cost_snapshot_hash,
                'denominator_quantity'=>$candidate['denominator'],'component_amounts'=>$candidate['components'],
                'source_evidence'=>array_merge($candidate['evidence'],['d09_source_hash'=>$sourceHash,'calculation_version'=>self::CALCULATION_VERSION]),
                'calculation_hash'=>$calculationHash,'created_by'=>$user->id]);
            $request=$this->approval->submit($freeze,'ACTUAL_COST_FREEZE',$user);
            $freeze->update(['approval_request_id'=>$request->id]);
            $costing=FgActualCosting::create(['company_id'=>$locked->company_id,'production_order_id'=>$locked->id,'actual_cost_freeze_id'=>$freeze->id,
                'valuation_object'=>'FG_ACTUAL','costing_version'=>$version,'fg_received_quantity'=>$candidate['denominator'],'actual_total_cost'=>$actualTotal,
                'actual_cost_per_pcs'=>$perPcs,'standard_cost_per_pcs'=>round((float)$locked->standard_cost_snapshot['manufacturing_total'],4),
                'provisional_fg_value'=>round(array_sum($candidate['provisional']),4),'component_variance_total'=>round(array_sum($candidate['variance']),4),
                'currency'=>(string)DB::table('companies')->where('id',$locked->company_id)->value('base_currency'),'calculation_version'=>self::CALCULATION_VERSION,
                'standard_snapshot_hash'=>$locked->standard_cost_snapshot_hash,'source_hash'=>$sourceHash,'calculation_hash'=>$calculationHash,
                'source_evidence'=>$candidate['evidence'],'completeness'=>$candidate['completeness'],'status'=>'PENDING_FREEZE','created_by'=>$user->id]);
            foreach(self::COMPONENTS as $component)$costing->components()->create(['company_id'=>$locked->company_id,'component'=>$component,
                'completeness_status'=>$candidate['completeness'][$component],'actual_cost'=>$candidate['components'][$component],
                'provisional_value'=>$candidate['provisional'][$component],'variance_amount'=>$candidate['variance'][$component],
                'source_evidence'=>$candidate['component_evidence'][$component],'source_hash'=>$this->hash($candidate['component_evidence'][$component]),'created_at'=>now()]);
            $this->audit->record('create',$costing,after:['policy'=>'D-09','costing_version'=>$version,'source_hash'=>$sourceHash]);
            return $costing->fresh(['components','freeze.approvalRequest']);
        });
    }

    public function finalize(FgActualCosting $costing,User $user): FgActualCosting
    {
        return DB::transaction(function()use($costing,$user):FgActualCosting{
            $locked=FgActualCosting::withoutGlobalScopes()->with('freeze')->whereKey($costing->id)->lockForUpdate()->firstOrFail();
            $this->access($user,(int)$locked->company_id);
            if($locked->status==='FROZEN')return $locked->fresh(['components','freeze']);
            $this->valuation->applyFreeze($locked->freeze,$user);
            $locked->update(['status'=>'FROZEN','frozen_at'=>now()]);
            $this->audit->record('approve',$locked,after:['status'=>'FROZEN','costing_version'=>$locked->costing_version]);
            return $locked->fresh(['components','freeze']);
        });
    }

    public function latest(ProductionOrder $mo,User $user): array
    {
        $locked=ProductionOrder::withoutGlobalScopes()->whereKey($mo->id)->firstOrFail();$this->access($user,(int)$locked->company_id);
        $costing=FgActualCosting::withoutGlobalScopes()->with(['components','freeze','productionOrder'])->where('company_id',$locked->company_id)
            ->where('production_order_id',$locked->id)->latest('costing_version')->first();
        return $costing?['status'=>$costing->status,'costing'=>$costing]:['status'=>'NOT_CALCULATED','costing'=>null,'fail_closed_reason'=>'D09_COSTING_NOT_CALCULATED'];
    }

    public function detail(FgActualCosting $costing,User $user): FgActualCosting
    {
        $row=FgActualCosting::withoutGlobalScopes()->with(['components','freeze.approvalRequest','productionOrder'])->whereKey($costing->id)->firstOrFail();
        $this->access($user,(int)$row->company_id);return $row;
    }

    private function candidate(ProductionOrder $mo,string $period): array
    {
        $receipts=DB::table('stock_ledger as l')->join('packing_lists as p',function($j):void{$j->on('p.id','=','l.source_document_id')->where('l.source_document_type','=','packing_lists');})
            ->join('stock_movements as sm',function($j):void{$j->on('sm.source_document_id','=','p.id')->where('sm.source_document_type','=','packing_lists')->where('sm.movement_type','=','PRODUCTION_RECEIPT');})
            ->where('l.company_id',$mo->company_id)->where('p.company_id',$mo->company_id)->where('p.production_order_id',$mo->id)
            ->where('l.movement_type','PRODUCTION_RECEIPT')->where('l.ownership','COMPANY')->orderBy('sm.created_at')->orderBy('sm.id')
            ->get(['l.id as ledger_id','sm.id as movement_id','p.id as packing_list_id','l.qty_in','l.total_cost']);
        $denominator=round((float)$receipts->sum('qty_in'),4);
        if($denominator<=0)throw new RuntimeException('FAIL_CLOSED: authoritative company-owned ITS PRODUCTION_RECEIPT quantity is zero or missing.');
        $movementIds=$receipts->pluck('movement_id')->unique()->values();
        foreach($movementIds as $id)if(DB::table('fg_valuation_events')->where('company_id',$mo->company_id)->where('production_order_id',$mo->id)->where('stock_movement_id',$id)->count()!==count(self::COMPONENTS))
            throw new RuntimeException('FAIL_CLOSED: D-07 FG valuation is incomplete for an authoritative receipt.');

        $materials=DB::table('stock_ledger as l')->leftJoin('materials as m','m.id','=','l.material_id')
            ->where('l.company_id',$mo->company_id)->where('l.ownership','COMPANY')->whereIn('l.movement_type',['MATERIAL_ISSUE','PRODUCTION_RETURN'])
            ->where(function($q)use($mo){$q->whereExists(function($s)use($mo){$s->selectRaw('1')->from('material_issues as i')->whereColumn('i.id','l.source_document_id')->where('l.source_document_type','material_issues')->where('i.production_order_id',$mo->id);})
                ->orWhereExists(function($s)use($mo){$s->selectRaw('1')->from('fabric_returns as r')->whereColumn('r.id','l.source_document_id')->where('l.source_document_type','fabric_returns')->where('r.production_order_id',$mo->id);});})
            ->get(['l.id','l.movement_type','l.total_cost','l.unit_cost','l.material_id','m.type']);
        $standard=$mo->standard_cost_snapshot;
        $components=[];$completeness=[];$componentEvidence=[];
        foreach(['FABRIC'=>'fabric','TRIM'=>'trim'] as $component=>$standardKey){
            $rows=$materials->filter(fn($r)=>($component==='FABRIC')===($r->type==='FABRIC'))->values();
            $missing=$rows->contains(fn($r)=>$r->unit_cost===null||$r->total_cost===null);
            $components[$component]=$missing?0.0:round((float)$rows->sum(fn($r)=>$r->movement_type==='MATERIAL_ISSUE'?(float)$r->total_cost:-(float)$r->total_cost),4);
            $completeness[$component]=$missing?'MISSING':($rows->isEmpty()?((float)$standard[$standardKey]===0.0?'NOT_APPLICABLE':'MISSING'):($components[$component]<0?'CONFLICT':'COMPLETE'));
            $componentEvidence[$component]=['authority'=>'ITS_MATERIAL_ISSUE_LESS_PRODUCTION_RETURN','ledger_ids'=>$rows->pluck('id')->all()];
        }
        $sam=(float)(DB::table('routing_versions')->where('id',$mo->routing_version_id)->value('total_sam')??0);
        $lineRate=DB::table('line_cost_rates')->where('company_id',$mo->company_id)->where('line_id',$mo->line_id)->where('period',$period)->first();
        $ohRate=DB::table('overhead_rates')->where('company_id',$mo->company_id)->where('period',$period)->first();
        $scanIds=DB::table('wip_valuation_events')->where('company_id',$mo->company_id)->where('production_order_id',$mo->id)->where('source_type','wip_transfers')->pluck('source_id')->unique()->values();
        foreach(['LABOR'=>[$lineRate,'cost_per_minute','labor'],'OVERHEAD'=>[$ohRate,'rate_per_minute','overhead']] as $component=>$cfg){
            [$rate,$field,$standardKey]=$cfg;$notApplicable=(float)$standard[$standardKey]===0.0;
            $complete=$notApplicable||($sam>0&&$rate!==null&&$scanIds->isNotEmpty());
            $components[$component]=$complete&&!$notApplicable?round($denominator*$sam*(float)$rate->{$field},4):0.0;
            $completeness[$component]=$notApplicable?'NOT_APPLICABLE':($complete?'COMPLETE':'MISSING');
            $componentEvidence[$component]=['authority'=>'FG_RECEIVED_QTY_X_ROUTING_SAM_X_RATE','routing_version_id'=>$mo->routing_version_id,'sam'=>$sam,'rate_id'=>$rate?->id,'period'=>$period,'wip_transfer_ids'=>$scanIds->all()];
        }
        $fees=DB::table('subcon_fees as f')->join('subcon_orders as j','j.id','=','f.subcon_order_id')->where('j.company_id',$mo->company_id)->where('j.production_order_id',$mo->id)->get(['f.id','f.total_fee']);
        $components['SUBCON']=round((float)$fees->sum('total_fee'),4);
        $completeness['SUBCON']=$fees->isEmpty()?((float)$standard['subcon']===0.0?'NOT_APPLICABLE':'MISSING'):'COMPLETE';
        $componentEvidence['SUBCON']=['authority'=>'SUBCON_FEES','fee_ids'=>$fees->pluck('id')->all()];
        $components['OTHER']=0.0;$completeness['OTHER']=(float)$standard['other']===0.0?'NOT_APPLICABLE':'MISSING';
        $componentEvidence['OTHER']=['authority'=>'NO_APPROVED_ACTUAL_SOURCE','standard_other'=>(float)$standard['other']];
        $provisional=[];$variance=[];
        foreach(self::COMPONENTS as $component){$provisional[$component]=round((float)DB::table('fg_valuation_events')->where('company_id',$mo->company_id)->where('production_order_id',$mo->id)->where('component',$component)->sum('provisional_value'),4);$variance[$component]=round($components[$component]-$provisional[$component],4);}
        return['denominator'=>$denominator,'components'=>$components,'completeness'=>$completeness,'component_evidence'=>$componentEvidence,'provisional'=>$provisional,'variance'=>$variance,
            'evidence'=>['receipt_ledger_ids'=>$receipts->pluck('ledger_id')->all(),'receipt_movement_ids'=>$movementIds->all(),'packing_list_ids'=>$receipts->pluck('packing_list_id')->unique()->values()->all(),
                'component_sources'=>$componentEvidence,'d06_wip_event_ids'=>DB::table('wip_valuation_events')->where('company_id',$mo->company_id)->where('production_order_id',$mo->id)->pluck('id')->all(),
                'd07_fg_event_ids'=>DB::table('fg_valuation_events')->where('company_id',$mo->company_id)->where('production_order_id',$mo->id)->pluck('id')->all()]];
    }

    private function hash(array $payload): string{return hash('sha256',json_encode($payload,JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));}
    private function access(User $user,int $companyId):void{if((int)$user->company_id!==$companyId&&!$user->companies()->whereKey($companyId)->exists())throw new RuntimeException('User does not have access to the costing company.');}
}
