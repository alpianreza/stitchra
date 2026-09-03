<?php

namespace Modules\Finance\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Approval\ApprovalEngine;
use Modules\Core\Models\ApprovalRequest;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Finance\Models\ActualCostFreeze;
use Modules\Finance\Models\MoValuationEligibility;
use Modules\Finance\Models\ValuationAllocationProfile;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Services\NamedProductionMeasureService;
use Modules\Production\Services\StandardCostSnapshotService;
use RuntimeException;

class ManufacturingValuationService
{
    public const POLICY_VERSION = 'D06_D07_V1';
    public const COMPONENTS = ['FABRIC','TRIM','LABOR','OVERHEAD','SUBCON','OTHER'];
    public const BOUNDARIES = ['CUTTING_TO_SEWING','SEWING_TO_FINISHING'];
    private const STANDARD_KEYS = ['FABRIC'=>'fabric','TRIM'=>'trim','LABOR'=>'labor','OVERHEAD'=>'overhead','SUBCON'=>'subcon','OTHER'=>'other'];
    private const BOUNDARY_MAP = [
        'CUTTING_TO_SEWING' => ['from'=>'CUTTING','to'=>'SEWING','measure'=>'CUT_OUTPUT'],
        'SEWING_TO_FINISHING' => ['from'=>'SEWING','to'=>'FINISHING','measure'=>'SEWING_FINAL_OUT'],
    ];

    public function __construct(
        private ApprovalEngine $approval,
        private AuditService $audit,
        private NamedProductionMeasureService $measures,
        private StandardCostSnapshotService $snapshots,
    ) {}

    public function createProfile(int $companyId, array $data, User $user): ValuationAllocationProfile
    {
        $this->access($user, $companyId);
        $rules = $data['rules'] ?? [];
        $this->validateRules($rules);
        return DB::transaction(function () use ($companyId,$data,$rules,$user): ValuationAllocationProfile {
            $profile = ValuationAllocationProfile::create([
                'company_id'=>$companyId,'code'=>$data['code'],'version'=>(int)$data['version'],
                'effective_date'=>$data['effective_date'],'status'=>'DRAFT','created_by'=>$user->id,
            ]);
            foreach ($rules as $rule) {
                $profile->rules()->create([
                    'company_id'=>$companyId,'component'=>strtoupper($rule['component']),
                    'stage'=>strtoupper($rule['stage']),'allocation_rule'=>strtoupper($rule['allocation_rule']),
                    'allocation_value'=>(float)$rule['allocation_value'],'allocation_mode'=>'CUMULATIVE',
                    'source_structure'=>$rule['source_structure'] ?? [],'created_by'=>$user->id,'created_at'=>now(),
                ]);
            }
            $request = $this->approval->submit($profile, 'VALUATION_ALLOCATION_PROFILE', $user);
            $profile->update(['approval_request_id'=>$request->id]);
            $this->audit->record('submit',$profile,after:['policy'=>self::POLICY_VERSION,'rule_count'=>count($rules)]);
            return $profile->fresh(['rules','approvalRequest']);
        });
    }

    public function activateProfile(ValuationAllocationProfile $profile, User $user): ValuationAllocationProfile
    {
        return DB::transaction(function () use ($profile,$user): ValuationAllocationProfile {
            $locked=ValuationAllocationProfile::withoutGlobalScopes()->with(['rules','approvalRequest'])->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $this->access($user,(int)$locked->company_id);
            if ($locked->status==='APPROVED') return $locked;
            $this->approvedRequest($locked->approvalRequest,'VALUATION_ALLOCATION_PROFILE',(int)$locked->id);
            $this->validateRules($locked->rules->map(fn($r)=>$r->toArray())->all());
            $locked->update(['status'=>'APPROVED']);
            $this->audit->record('approve',$locked,after:['status'=>'APPROVED']);
            return $locked->fresh(['rules','approvalRequest']);
        });
    }

    public function createEligibility(ProductionOrder $mo, ValuationAllocationProfile $profile, string $effectiveDate, User $user): MoValuationEligibility
    {
        return DB::transaction(function () use ($mo,$profile,$effectiveDate,$user): MoValuationEligibility {
            $locked=ProductionOrder::withoutGlobalScopes()->whereKey($mo->id)->lockForUpdate()->firstOrFail();
            $this->access($user,(int)$locked->company_id);
            $this->snapshots->verify($locked);
            $p=ValuationAllocationProfile::withoutGlobalScopes()->with('rules')->where('company_id',$locked->company_id)->whereKey($profile->id)->where('status','APPROVED')->lockForUpdate()->firstOrFail();
            if ($p->effective_date->toDateString()>$effectiveDate) throw new RuntimeException('Allocation profile is not effective on eligibility date.');
            if (DB::table('wip_valuation_events')->where('company_id',$locked->company_id)->where('production_order_id',$locked->id)->exists()
                || DB::table('fg_valuation_events')->where('company_id',$locked->company_id)->where('production_order_id',$locked->id)->exists()) throw new RuntimeException('Historical MO with valuation events cannot be newly enrolled.');
            $snapshot=['profile'=>['id'=>$p->id,'code'=>$p->code,'version'=>$p->version,'effective_date'=>$p->effective_date->toDateString()],
                'bom_version_id'=>$locked->bom_version_id,'routing_version_id'=>$locked->routing_version_id,
                'routing_total_sam'=>(float)(DB::table('routing_versions')->where('id',$locked->routing_version_id)->value('total_sam')??0),
                'rules'=>$p->rules->sortBy(fn($r)=>$r->stage.'|'.$r->component)->map(fn($r)=>[
                    'component'=>$r->component,'stage'=>$r->stage,'allocation_rule'=>$r->allocation_rule,
                    'allocation_value'=>(float)$r->allocation_value,'allocation_mode'=>$r->allocation_mode,'source_structure'=>$r->source_structure,
                ])->values()->all()];
            $hash=$this->hash($snapshot);
            $eligibility=MoValuationEligibility::create([
                'company_id'=>$locked->company_id,'production_order_id'=>$locked->id,'allocation_profile_id'=>$p->id,
                'policy_version'=>self::POLICY_VERSION,'standard_snapshot_hash'=>$locked->standard_cost_snapshot_hash,
                'allocation_snapshot'=>$snapshot,'allocation_snapshot_hash'=>$hash,'effective_date'=>$effectiveDate,
                'status'=>'PENDING','created_by'=>$user->id,
            ]);
            $request=$this->approval->submit($eligibility,'MO_VALUATION_ELIGIBILITY',$user);
            $eligibility->update(['approval_request_id'=>$request->id]);
            $this->audit->record('submit',$eligibility,after:['policy'=>self::POLICY_VERSION,'allocation_snapshot_hash'=>$hash]);
            return $eligibility->fresh(['approvalRequest']);
        });
    }

    public function activateEligibility(MoValuationEligibility $eligibility, User $user): MoValuationEligibility
    {
        return DB::transaction(function () use ($eligibility,$user): MoValuationEligibility {
            $locked=MoValuationEligibility::withoutGlobalScopes()->with('approvalRequest')->whereKey($eligibility->id)->lockForUpdate()->firstOrFail();
            $this->access($user,(int)$locked->company_id);
            if ($locked->status==='APPROVED') return $locked;
            $this->approvedRequest($locked->approvalRequest,'MO_VALUATION_ELIGIBILITY',(int)$locked->id);
            $mo=ProductionOrder::withoutGlobalScopes()->where('company_id',$locked->company_id)->whereKey($locked->production_order_id)->lockForUpdate()->firstOrFail();
            $this->snapshots->verify($mo);
            if (!hash_equals($locked->standard_snapshot_hash,(string)$mo->standard_cost_snapshot_hash)) throw new RuntimeException('Standard snapshot changed after eligibility proposal.');
            if (!hash_equals($locked->allocation_snapshot_hash,$this->hash($locked->allocation_snapshot))) throw new RuntimeException('Allocation snapshot integrity failure.');
            $locked->update(['status'=>'APPROVED','approved_by'=>$user->id,'approved_at'=>now()]);
            $this->audit->record('approve',$locked,after:['status'=>'APPROVED','policy'=>self::POLICY_VERSION]);
            return $locked->fresh();
        });
    }

    public function valueWipTransfer(ProductionOrder $mo, int $transferId, User $user): array
    {
        return DB::transaction(function () use ($mo,$transferId,$user): array {
            $locked=ProductionOrder::withoutGlobalScopes()->whereKey($mo->id)->lockForUpdate()->firstOrFail();
            $this->access($user,(int)$locked->company_id);
            $eligibility=$this->eligibility($locked);
            $transfer=DB::table('wip_transfers')->where('company_id',$locked->company_id)->where('production_order_id',$locked->id)->where('id',$transferId)->lockForUpdate()->first();
            if (!$transfer) throw new RuntimeException('FAIL_CLOSED: authoritative WIP transfer not found.');
            $boundary=$transfer->from_stage.'_TO_'.$transfer->to_stage;
            $map=self::BOUNDARY_MAP[$boundary]??null;
            if (!$map) throw new RuntimeException('FAIL_CLOSED: transfer boundary is not valued by D-06.');
            $qty=(float)$transfer->qty;
            if ($qty===0.0) return ['status'=>'NO_ELIGIBLE_QUANTITY','events'=>[]];
            if ($qty<0) throw new RuntimeException('FAIL_CLOSED: invalid WIP transfer quantity.');
            $measure=$this->measures->measure($locked,$map['measure']);
            if (($measure['status']??null)!=='DEFINED'||$measure['qty']===null) throw new RuntimeException('FAIL_CLOSED: missing Named Measure '.$map['measure'].'.');
            $transferred=(float)DB::table('wip_transfers')->where('company_id',$locked->company_id)->where('production_order_id',$locked->id)
                ->where('from_stage',$map['from'])->where('to_stage',$map['to'])->where('id','<=',$transfer->id)->sum('qty');
            if ($transferred-(float)$measure['qty']>0.0001) throw new RuntimeException('FAIL_CLOSED: transfer quantity exceeds Named Measure '.$map['measure'].'.');
            $events=[];
            if ($boundary==='SEWING_TO_FINISHING') $events=array_merge($events,$this->writeWipComponentEvents($locked,$eligibility,$transfer,$boundary,'SEWING',$map['measure'],'RELIEF','CUTTING_TO_SEWING',-$qty,$user));
            $events=array_merge($events,$this->writeWipComponentEvents($locked,$eligibility,$transfer,$boundary,$map['to'],$map['measure'],'ADD',$boundary,$qty,$user));
            return ['status'=>'VALUED','events'=>$events];
        });
    }

    public function valueFgReceipt(ProductionOrder $mo, int $movementId, User $user): array
    {
        return DB::transaction(function () use ($mo,$movementId,$user): array {
            $locked=ProductionOrder::withoutGlobalScopes()->whereKey($mo->id)->lockForUpdate()->firstOrFail();
            $this->access($user,(int)$locked->company_id);
            $eligibility=$this->eligibility($locked);
            $movement=DB::table('stock_movements')->where('company_id',$locked->company_id)->where('movement_type','PRODUCTION_RECEIPT')
                ->where('source_document_type','packing_lists')->where('id',$movementId)->lockForUpdate()->first();
            if (!$movement) throw new RuntimeException('FAIL_CLOSED: authoritative ITS PRODUCTION_RECEIPT not found.');
            $packing=DB::table('packing_lists')->where('company_id',$locked->company_id)->where('production_order_id',$locked->id)->where('id',$movement->source_document_id)->lockForUpdate()->first();
            if (!$packing) throw new RuntimeException('FAIL_CLOSED: receipt is not traceable to the MO Packing List.');
            $qty=(float)DB::table('stock_ledger')->where('company_id',$locked->company_id)->where('movement_type','PRODUCTION_RECEIPT')
                ->where('source_document_type','packing_lists')->where('source_document_id',$packing->id)->sum('qty_in');
            if ($qty===0.0) return ['status'=>'NO_ELIGIBLE_QUANTITY','events'=>[]];
            $ordered=DB::table('stock_movements as m')->join('packing_lists as p',function($join):void{$join->on('p.id','=','m.source_document_id')->where('m.source_document_type','=','packing_lists');})
                ->where('m.company_id',$locked->company_id)->where('p.company_id',$locked->company_id)->where('p.production_order_id',$locked->id)
                ->where('m.movement_type','PRODUCTION_RECEIPT')->where(function($q)use($movement){$q->where('m.created_at','<',$movement->created_at)->orWhere(fn($x)=>$x->where('m.created_at',$movement->created_at)->where('m.id','<=',$movement->id));})
                ->orderBy('m.created_at')->orderBy('m.id')->get(['m.id']);
            $priorIds=$ordered->pluck('id')->reject(fn($id)=>(int)$id===(int)$movement->id);
            foreach ($priorIds as $priorId) if (!DB::table('fg_valuation_events')->where('company_id',$locked->company_id)->where('stock_movement_id',$priorId)->exists()) throw new RuntimeException('FAIL_CLOSED: earlier ITS receipt must be valued first.');
            $cumulative=(float)DB::table('stock_ledger as l')->join('packing_lists as p',function($join):void{$join->on('p.id','=','l.source_document_id')->where('l.source_document_type','=','packing_lists');})
                ->join('stock_movements as m',function($join):void{$join->on('m.source_document_id','=','p.id')->where('m.source_document_type','=','packing_lists')->where('m.movement_type','=','PRODUCTION_RECEIPT');})
                ->where('l.company_id',$locked->company_id)->where('p.production_order_id',$locked->id)->whereIn('m.id',$ordered->pluck('id'))->where('l.movement_type','PRODUCTION_RECEIPT')->sum('l.qty_in');
            $finishingQty=(float)DB::table('wip_valuation_events')->where('company_id',$locked->company_id)->where('production_order_id',$locked->id)->where('valuation_stage','FINISHING')->sum('quantity_delta');
            if ($finishingQty+0.0001<$qty) throw new RuntimeException('FAIL_CLOSED: insufficient accumulated Finishing WIP lineage for receipt.');
            $events=[];
            foreach (self::COMPONENTS as $component) {
                $totalQty=(float)DB::table('wip_valuation_events')->where('company_id',$locked->company_id)->where('production_order_id',$locked->id)->where('valuation_stage','FINISHING')->where('event_kind','ADD')->where('component',$component)->sum('quantity_delta');
                $totalValue=(float)DB::table('wip_valuation_events')->where('company_id',$locked->company_id)->where('production_order_id',$locked->id)->where('valuation_stage','FINISHING')->where('event_kind','ADD')->where('component',$component)->sum('provisional_value');
                if ($totalQty<=0||$cumulative-$totalQty>0.0001) throw new RuntimeException('FAIL_CLOSED: cumulative FG quantity exceeds valued Finishing WIP.');
                $unit=round($totalValue/$totalQty,6);
                $target=round($cumulative*$unit,4);
                $prior=(float)DB::table('fg_valuation_events')->where('company_id',$locked->company_id)->where('production_order_id',$locked->id)->where('component',$component)->whereIn('stock_movement_id',$priorIds)->sum('provisional_value');
                $delta=round($target-$prior,4);
                $lineage=['finishing_add_quantity'=>$totalQty,'finishing_add_value'=>$totalValue,'prior_receipts'=>$priorIds->values()->all()];
                $payload=['company_id'=>$locked->company_id,'mo_id'=>$locked->id,'movement_id'=>$movement->id,'component'=>$component,'qty'=>$qty,'cumulative'=>$cumulative,'unit'=>$unit,'value'=>$delta,'lineage'=>$lineage];
                $hash=$this->hash($payload);
                $existing=DB::table('fg_valuation_events')->where('company_id',$locked->company_id)->where('stock_movement_id',$movement->id)->where('component',$component)->first();
                if ($existing) { if (!hash_equals($existing->payload_hash,$hash)) throw new RuntimeException('CONFLICT: FG valuation identity has a different payload.'); $events[]=(array)$existing; continue; }
                DB::table('fg_valuation_events')->insert(['company_id'=>$locked->company_id,'production_order_id'=>$locked->id,'eligibility_id'=>$eligibility->id,
                    'stock_movement_id'=>$movement->id,'packing_list_id'=>$packing->id,'component'=>$component,'receipt_quantity'=>$qty,'cumulative_quantity'=>$cumulative,
                    'unit_basis'=>$unit,'provisional_value'=>$delta,'standard_snapshot_hash'=>$locked->standard_cost_snapshot_hash,
                    'wip_lineage_hash'=>$this->hash($lineage),'payload_hash'=>$hash,'event_at'=>$movement->created_at,'created_by'=>$user->id,'created_at'=>now()]);
                $this->insertWipEvent($locked,$eligibility,'stock_movements',(int)$movement->id,'FINISHING_TO_FG','FINISHING','FG_RECEIVED_QTY','RELIEF',$component,-$qty,-$unit,-$delta,$movement->created_at,$user);
                $events[]=(array)DB::table('fg_valuation_events')->where('company_id',$locked->company_id)->where('stock_movement_id',$movement->id)->where('component',$component)->first();
            }
            $this->audit->record('create','fg_valuation_events',documentId:(int)$movement->id,after:['mo_id'=>$locked->id,'receipt_qty'=>$qty,'components'=>self::COMPONENTS]);
            return ['status'=>'VALUED','events'=>$events];
        });
    }

    public function createFreeze(ProductionOrder $mo, string $period, ?float $otherAmount, ?string $otherSource, User $user): ActualCostFreeze
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$period)) throw new RuntimeException('Freeze period must use YYYY-MM.');
        return DB::transaction(function () use ($mo,$period,$otherAmount,$otherSource,$user): ActualCostFreeze {
            $locked=ProductionOrder::withoutGlobalScopes()->whereKey($mo->id)->lockForUpdate()->firstOrFail();
            $this->access($user,(int)$locked->company_id);
            $eligibility=$this->eligibility($locked);
            $actual=$this->actualComponents($locked,$period,$otherAmount,$otherSource);
            $version=(int)ActualCostFreeze::withoutGlobalScopes()->where('company_id',$locked->company_id)->where('production_order_id',$locked->id)->lockForUpdate()->max('freeze_version')+1;
            $payload=['mo_id'=>$locked->id,'version'=>$version,'period'=>$period,'standard_hash'=>$locked->standard_cost_snapshot_hash,'actual'=>$actual];
            $freeze=ActualCostFreeze::create(['company_id'=>$locked->company_id,'production_order_id'=>$locked->id,'eligibility_id'=>$eligibility->id,
                'freeze_version'=>$version,'status'=>'PENDING','period'=>$period,'standard_snapshot_hash'=>$locked->standard_cost_snapshot_hash,
                'denominator_quantity'=>$actual['denominator'],'component_amounts'=>$actual['components'],'source_evidence'=>$actual['sources'],
                'calculation_hash'=>$this->hash($payload),'created_by'=>$user->id]);
            $request=$this->approval->submit($freeze,'ACTUAL_COST_FREEZE',$user);
            $freeze->update(['approval_request_id'=>$request->id]);
            $this->audit->record('submit',$freeze,after:['freeze_version'=>$version,'calculation_hash'=>$freeze->calculation_hash]);
            return $freeze->fresh('approvalRequest');
        });
    }

    public function applyFreeze(ActualCostFreeze $freeze, User $user): array
    {
        return DB::transaction(function () use ($freeze,$user): array {
            $locked=ActualCostFreeze::withoutGlobalScopes()->with(['approvalRequest','productionOrder'])->whereKey($freeze->id)->lockForUpdate()->firstOrFail();
            $this->access($user,(int)$locked->company_id);
            if ($locked->status==='FROZEN') return ['freeze'=>$locked,'adjustments'=>DB::table('valuation_adjustments')->where('actual_cost_freeze_id',$locked->id)->get()];
            $this->approvedRequest($locked->approvalRequest,'ACTUAL_COST_FREEZE',(int)$locked->id);
            $mo=$locked->productionOrder;
            if (!hash_equals($locked->standard_snapshot_hash,(string)$mo->standard_cost_snapshot_hash)) throw new RuntimeException('Standard snapshot differs from freeze proposal.');
            $objects=$this->quantityState($mo);
            if ($objects['total']<=0) throw new RuntimeException('FAIL_CLOSED: no authoritative quantity state for variance.');
            foreach (self::COMPONENTS as $component) $this->writeAdjustments($locked,$component,(float)$locked->component_amounts[$component],$objects,$user);
            $locked->update(['status'=>'FROZEN','frozen_by'=>$user->id,'frozen_at'=>now()]);
            $this->audit->record('approve',$locked,after:['status'=>'FROZEN','freeze_version'=>$locked->freeze_version]);
            return ['freeze'=>$locked->fresh(),'adjustments'=>DB::table('valuation_adjustments')->where('actual_cost_freeze_id',$locked->id)->orderBy('id')->get()];
        });
    }

    public function status(ProductionOrder $mo, User $user): array
    {
        $loaded=ProductionOrder::withoutGlobalScopes()->whereKey($mo->id)->firstOrFail();
        $this->access($user,(int)$loaded->company_id);
        $eligibility=MoValuationEligibility::withoutGlobalScopes()->where('company_id',$loaded->company_id)->where('production_order_id',$loaded->id)->first();
        return ['policy'=>self::POLICY_VERSION,'production_order'=>['id'=>$loaded->id,'doc_no'=>$loaded->doc_no,'status'=>$loaded->status,'standard_snapshot_hash'=>$loaded->standard_cost_snapshot_hash],
            'eligibility'=>$eligibility,'wip'=>['events'=>DB::table('wip_valuation_events')->where('company_id',$loaded->company_id)->where('production_order_id',$loaded->id)->orderBy('id')->get(),
                'totals'=>DB::table('wip_valuation_events')->where('company_id',$loaded->company_id)->where('production_order_id',$loaded->id)->selectRaw('valuation_stage, component, SUM(quantity_delta) quantity, SUM(provisional_value) value')->groupBy('valuation_stage','component')->get()],
            'fg'=>['events'=>DB::table('fg_valuation_events')->where('company_id',$loaded->company_id)->where('production_order_id',$loaded->id)->orderBy('id')->get()],
            'freeze'=>ActualCostFreeze::withoutGlobalScopes()->where('company_id',$loaded->company_id)->where('production_order_id',$loaded->id)->orderByDesc('freeze_version')->first(),
            'adjustments'=>DB::table('valuation_adjustments')->where('company_id',$loaded->company_id)->where('production_order_id',$loaded->id)->orderBy('id')->get(),
            'fail_closed_reason'=>$eligibility?null:'MO_VALUATION_ELIGIBILITY_NOT_APPROVED'];
    }

    private function writeWipComponentEvents(ProductionOrder $mo, MoValuationEligibility $eligibility, object $transfer, string $sourceBoundary, string $stage, string $measure, string $kind, string $allocationBoundary, float $qty, User $user): array
    {
        $rows=[];
        foreach (self::COMPONENTS as $component) {
            $rule=$this->rule($eligibility,$component,$allocationBoundary);
            $standard=(float)$mo->standard_cost_snapshot[self::STANDARD_KEYS[$component]];
            $unit=round($standard*(float)$rule['allocation_value'],6);
            if ($kind==='RELIEF') $unit=-abs($unit);
            $value=round(abs($qty)*$unit,4);
            $rows[]=$this->insertWipEvent($mo,$eligibility,'wip_transfers',(int)$transfer->id,$sourceBoundary,$stage,$measure,$kind,$component,$qty,$unit,$value,$transfer->transferred_at,$user);
        }
        return $rows;
    }

    private function insertWipEvent(ProductionOrder $mo, MoValuationEligibility $eligibility, string $sourceType, int $sourceId, string $boundary, string $stage, string $measure, string $kind, string $component, float $qty, float $unit, float $value, string $eventAt, User $user): array
    {
        $payload=['company_id'=>$mo->company_id,'mo_id'=>$mo->id,'source_type'=>$sourceType,'source_id'=>$sourceId,'stage'=>$stage,'component'=>$component,'qty'=>round($qty,4),'unit'=>round($unit,6),'value'=>round($value,4),'boundary'=>$boundary,'kind'=>$kind];
        $hash=$this->hash($payload);
        $existing=DB::table('wip_valuation_events')->where('company_id',$mo->company_id)->where('source_type',$sourceType)->where('source_id',$sourceId)->where('valuation_stage',$stage)->where('component',$component)->first();
        if ($existing) { if (!hash_equals($existing->payload_hash,$hash)) throw new RuntimeException('CONFLICT: WIP valuation identity has a different payload.'); return (array)$existing; }
        try { DB::table('wip_valuation_events')->insert(['company_id'=>$mo->company_id,'production_order_id'=>$mo->id,'eligibility_id'=>$eligibility->id,
            'source_type'=>$sourceType,'source_id'=>$sourceId,'boundary'=>$boundary,'valuation_stage'=>$stage,'measure_key'=>$measure,'event_kind'=>$kind,'component'=>$component,
            'quantity_delta'=>round($qty,4),'unit_basis'=>round($unit,6),'provisional_value'=>round($value,4),'standard_snapshot_hash'=>$mo->standard_cost_snapshot_hash,
            'allocation_snapshot_hash'=>$eligibility->allocation_snapshot_hash,'payload_hash'=>$hash,'event_at'=>$eventAt,'created_by'=>$user->id,'created_at'=>now()]); }
        catch (QueryException) { $race=DB::table('wip_valuation_events')->where('company_id',$mo->company_id)->where('source_type',$sourceType)->where('source_id',$sourceId)->where('valuation_stage',$stage)->where('component',$component)->first(); if(!$race||!hash_equals($race->payload_hash,$hash)) throw new RuntimeException('CONFLICT: concurrent WIP valuation payload differs.'); return (array)$race; }
        return (array)DB::table('wip_valuation_events')->where('company_id',$mo->company_id)->where('source_type',$sourceType)->where('source_id',$sourceId)->where('valuation_stage',$stage)->where('component',$component)->first();
    }

    private function actualComponents(ProductionOrder $mo, string $period, ?float $otherAmount, ?string $otherSource): array
    {
        $denominator=(float)$this->measures->measure($mo,'FG_RECEIVED_QTY')['qty'];
        if ($denominator<=0) throw new RuntimeException('FAIL_CLOSED: D-09 FG_RECEIVED_QTY denominator is missing or zero.');
        $materialRows=DB::table('stock_ledger as l')->leftJoin('materials as m','m.id','=','l.material_id')
            ->where('l.company_id',$mo->company_id)->where('l.ownership','COMPANY')->whereIn('l.movement_type',['MATERIAL_ISSUE','PRODUCTION_RETURN'])
            ->where(function($q)use($mo){$q->whereExists(function($s)use($mo){$s->selectRaw('1')->from('material_issues as i')->whereColumn('i.id','l.source_document_id')->where('l.source_document_type','material_issues')->where('i.production_order_id',$mo->id);})
                ->orWhereExists(function($s)use($mo){$s->selectRaw('1')->from('fabric_returns as r')->whereColumn('r.id','l.source_document_id')->where('l.source_document_type','fabric_returns')->where('r.production_order_id',$mo->id);});})
            ->get(['l.movement_type','l.total_cost','l.unit_cost','m.type']);
        if ($materialRows->contains(fn($r)=>$r->total_cost===null||$r->unit_cost===null)) throw new RuntimeException('FAIL_CLOSED: material actual cost is incomplete.');
        $material=function(string $type)use($materialRows):float{return round((float)$materialRows->filter(fn($r)=>($type==='FABRIC')===($r->type==='FABRIC'))->sum(fn($r)=>$r->movement_type==='MATERIAL_ISSUE'?(float)$r->total_cost:-(float)$r->total_cost),4);};
        $sam=(float)(DB::table('routing_versions')->where('id',$mo->routing_version_id)->value('total_sam')??0);
        $lineRate=$mo->line_id?DB::table('line_cost_rates')->where('company_id',$mo->company_id)->where('line_id',$mo->line_id)->where('period',$period)->value('cost_per_minute'):null;
        $ohRate=DB::table('overhead_rates')->where('company_id',$mo->company_id)->where('period',$period)->value('rate_per_minute');
        $standard=$mo->standard_cost_snapshot;
        if ((float)$standard['labor']>0&&($sam<=0||$lineRate===null)) throw new RuntimeException('FAIL_CLOSED: labor actual source is incomplete.');
        if ((float)$standard['overhead']>0&&($sam<=0||$ohRate===null)) throw new RuntimeException('FAIL_CLOSED: overhead actual source is incomplete.');
        if ((float)$standard['other']!==0.0&&($otherAmount===null||trim((string)$otherSource)==='')) throw new RuntimeException('FAIL_CLOSED: Other actual cost requires explicit source evidence.');
        $subcon=round((float)DB::table('subcon_fees as f')->join('subcon_orders as j','j.id','=','f.subcon_order_id')->where('j.company_id',$mo->company_id)->where('j.production_order_id',$mo->id)->sum('f.total_fee'),4);
        return ['denominator'=>$denominator,'components'=>['FABRIC'=>$material('FABRIC'),'TRIM'=>$material('TRIM'),
            'LABOR'=>round($denominator*$sam*(float)($lineRate??0),4),'OVERHEAD'=>round($denominator*$sam*(float)($ohRate??0),4),
            'SUBCON'=>$subcon,'OTHER'=>round((float)($otherAmount??0),4)],
            'sources'=>['material'=>'company ITS MATERIAL_ISSUE less PRODUCTION_RETURN by material type','labor'=>['fg_received_qty'=>$denominator,'sam'=>$sam,'line_rate'=>$lineRate,'period'=>$period],
                'overhead'=>['fg_received_qty'=>$denominator,'sam'=>$sam,'oh_rate'=>$ohRate,'period'=>$period],'subcon'=>'linked subcon_fees','other'=>$otherSource??'STANDARD_OTHER_ZERO']];
    }

    private function quantityState(ProductionOrder $mo): array
    {
        $wip=(float)DB::table('wip_valuation_events')->where('company_id',$mo->company_id)->where('production_order_id',$mo->id)->where('component','FABRIC')->sum('quantity_delta');
        $received=(float)$this->measures->measure($mo,'FG_RECEIVED_QTY')['qty'];
        $shipped=(float)$this->measures->measure($mo,'SHIPPED_QTY')['qty'];
        if ($shipped-$received>0.0001) throw new RuntimeException('FAIL_CLOSED: shipped quantity exceeds FG received quantity.');
        return ['WIP'=>max(0,$wip),'FG_ON_HAND'=>max(0,$received-$shipped),'SHIPPED_HANDOFF'=>max(0,$shipped),'total'=>max(0,$wip)+max(0,$received)];
    }

    private function writeAdjustments(ActualCostFreeze $freeze, string $component, float $actualTotal, array $objects, User $user): void
    {
        $provisional=['WIP'=>(float)DB::table('wip_valuation_events')->where('company_id',$freeze->company_id)->where('production_order_id',$freeze->production_order_id)->where('component',$component)->sum('provisional_value'),
            'FG_ON_HAND'=>0.0,'SHIPPED_HANDOFF'=>0.0];
        $fgTotal=(float)DB::table('fg_valuation_events')->where('company_id',$freeze->company_id)->where('production_order_id',$freeze->production_order_id)->where('component',$component)->sum('provisional_value');
        $fgQty=$objects['FG_ON_HAND']+$objects['SHIPPED_HANDOFF'];
        if ($fgQty>0) { $provisional['FG_ON_HAND']=round($fgTotal*$objects['FG_ON_HAND']/$fgQty,4); $provisional['SHIPPED_HANDOFF']=round($fgTotal-$provisional['FG_ON_HAND'],4); }
        $active=collect(['WIP','FG_ON_HAND','SHIPPED_HANDOFF'])->filter(fn($o)=>$objects[$o]>0)->values();
        $allocated=0.0;
        foreach ($active as $index=>$object) {
            $actual=$index===$active->count()-1?round($actualTotal-$allocated,4):round($actualTotal*$objects[$object]/$objects['total'],4);
            $allocated=round($allocated+$actual,4);
            $variance=round($actual-$provisional[$object],4);
            $identity=implode('|',[$freeze->company_id,$freeze->production_order_id,$freeze->freeze_version,$object,$component]);
            $payload=['identity'=>$identity,'quantity'=>$objects[$object],'provisional'=>$provisional[$object],'actual'=>$actual,'variance'=>$variance,'calculation_hash'=>$freeze->calculation_hash];
            $hash=$this->hash($payload);
            $existing=DB::table('valuation_adjustments')->where('company_id',$freeze->company_id)->where('production_order_id',$freeze->production_order_id)->where('freeze_version',$freeze->freeze_version)->where('valuation_object',$object)->where('component',$component)->first();
            if ($existing) { if(!hash_equals($existing->payload_hash,$hash)) throw new RuntimeException('CONFLICT: variance identity has a different payload.'); continue; }
            DB::table('valuation_adjustments')->insert(['company_id'=>$freeze->company_id,'production_order_id'=>$freeze->production_order_id,'actual_cost_freeze_id'=>$freeze->id,
                'freeze_version'=>$freeze->freeze_version,'valuation_object'=>$object,'component'=>$component,'affected_quantity'=>$objects[$object],
                'provisional_value'=>$provisional[$object],'actual_value'=>$actual,'variance_amount'=>$variance,'event_date'=>now()->toDateString(),
                'currency'=>(string)DB::table('companies')->where('id',$freeze->company_id)->value('base_currency'),'source_identity'=>$identity,
                'payload_hash'=>$hash,'created_by'=>$user->id,'created_at'=>now()]);
        }
    }

    private function eligibility(ProductionOrder $mo): MoValuationEligibility
    {
        $eligibility=MoValuationEligibility::withoutGlobalScopes()->where('company_id',$mo->company_id)->where('production_order_id',$mo->id)->where('status','APPROVED')->lockForUpdate()->first();
        if (!$eligibility) throw new RuntimeException('FAIL_CLOSED: MO valuation eligibility is not approved.');
        if ($eligibility->effective_date->isFuture()) throw new RuntimeException('FAIL_CLOSED: MO valuation eligibility is not effective.');
        if (!hash_equals($eligibility->standard_snapshot_hash,(string)$mo->standard_cost_snapshot_hash)) throw new RuntimeException('FAIL_CLOSED: standard snapshot identity mismatch.');
        return $eligibility;
    }

    private function rule(MoValuationEligibility $eligibility, string $component, string $boundary): array
    {
        $rule=collect($eligibility->allocation_snapshot['rules']??[])->first(fn($r)=>$r['component']===$component&&$r['stage']===$boundary);
        if (!$rule) throw new RuntimeException("FAIL_CLOSED: allocation rule missing for {$component}/{$boundary}.");
        return $rule;
    }

    private function validateRules(array $rules): void
    {
        $keys=[];
        foreach ($rules as $rule) {
            $component=strtoupper((string)($rule['component']??''));$stage=strtoupper((string)($rule['stage']??''));$mode=strtoupper((string)($rule['allocation_mode']??'CUMULATIVE'));
            if (!in_array($component,self::COMPONENTS,true)||!in_array($stage,self::BOUNDARIES,true)) throw new RuntimeException('Allocation component or boundary is invalid.');
            if ($mode!=='CUMULATIVE') throw new RuntimeException('Batch 3 allocation rules must be cumulative snapshots.');
            $value=(float)($rule['allocation_value']??-1);if($value<0||$value>1) throw new RuntimeException('Allocation value must be between 0 and 1.');
            $key=$component.'|'.$stage;if(isset($keys[$key])) throw new RuntimeException('Duplicate allocation rule '.$key);$keys[$key]=$value;
        }
        foreach(self::COMPONENTS as $component){foreach(self::BOUNDARIES as $boundary)if(!array_key_exists($component.'|'.$boundary,$keys))throw new RuntimeException("FAIL_CLOSED: allocation rule missing for {$component}/{$boundary}.");
            if($keys[$component.'|SEWING_TO_FINISHING']+0.00000001<$keys[$component.'|CUTTING_TO_SEWING'])throw new RuntimeException('Cumulative allocation cannot decrease across WIP boundaries.');}
    }

    private function approvedRequest(?ApprovalRequest $request, string $type, int $id): void
    {
        if(!$request||$request->doc_type!==$type||(int)$request->doc_id!==$id||$request->status!=='APPROVED'||$request->is_active) throw new RuntimeException("{$type} approval is not complete.");
    }
    private function hash(array $payload): string { return hash('sha256',json_encode($payload,JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR)); }
    private function access(User $user,int $companyId): void { if((int)$user->company_id!==$companyId&&!$user->companies()->whereKey($companyId)->exists()) throw new RuntimeException('User does not have access to the valuation company.'); }
}
