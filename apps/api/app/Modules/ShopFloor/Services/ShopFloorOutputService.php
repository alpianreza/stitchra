<?php

namespace Modules\ShopFloor\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Cutting\Models\Bundle;
use Modules\Production\Models\ProductionOrder;
use Modules\ShopFloor\Models\FinishingOutput;
use Modules\ShopFloor\Models\LineOutput;
use Modules\ShopFloor\Models\LineOutputEntry;
use Modules\ShopFloor\Models\ProductionScan;
use RuntimeException;

class ShopFloorOutputService
{
    public function __construct(private AuditService $audit) {}

    public function recordSewingFinal(Bundle $bundle, ProductionScan $scan, ProductionOrder $mo, User $user): LineOutput
    {
        if ($scan->stage !== 'SEWING' || $scan->direction !== 'OUT' || $scan->line_id === null) throw new RuntimeException('Final Sewing OUT wajib memiliki source scan OUT dan line.');
        if (LineOutputEntry::withoutGlobalScopes()->where('source_scan_id', $scan->id)->exists()) throw new RuntimeException('Final Sewing OUT sudah diposting.');
        $date = $scan->scanned_at->toDateString();
        DB::table('line_outputs')->insertOrIgnore(['company_id'=>$bundle->company_id,'production_order_id'=>$mo->id,'line_id'=>$scan->line_id,'output_date'=>$date,'qty'=>0,'created_by'=>$user->id,'created_at'=>now(),'updated_at'=>now()]);
        $output = LineOutput::withoutGlobalScopes()->where('company_id',$bundle->company_id)->where('production_order_id',$mo->id)->where('line_id',$scan->line_id)->where('output_date',$date)->lockForUpdate()->firstOrFail();
        LineOutputEntry::create(['company_id'=>$bundle->company_id,'line_output_id'=>$output->id,'source_scan_id'=>$scan->id,'bundle_id'=>$bundle->id,'qty'=>$scan->qty,'recorded_at'=>$scan->scanned_at,'created_by'=>$user->id,'created_at'=>now()]);
        $qty=(float)$output->entries()->sum('qty');
        $target=(float)DB::table('line_loadings')->where('company_id',$bundle->company_id)->where('production_order_id',$mo->id)->where('line_id',$scan->line_id)->whereDate('plan_date',$date)->sum('planned_qty');
        $output->update(['qty'=>$qty,'target_qty'=>$target>0?$target:null,'achievement_pct'=>$target>0?round($qty/$target*100,4):null,'updated_by'=>$user->id]);
        $this->audit->record('update',$output,after:['source_scan_id'=>$scan->id,'bundle_no'=>$bundle->bundle_no,'qty'=>$qty,'target_qty'=>$target>0?$target:null]);
        return $output;
    }

    public function eligibleFinishing(int $companyId, ?int $moId=null): array
    {
        $query=Bundle::withoutGlobalScopes()->where('company_id',$companyId)->where('status','ACTIVE')->where('current_stage','FINISHING');
        if($moId)$query->where('production_order_id',$moId);
        return $query->orderBy('bundle_no')->get()->map(function(Bundle $bundle)use($companyId){
            if(FinishingOutput::withoutGlobalScopes()->where('company_id',$companyId)->where('bundle_id',$bundle->id)->exists())return null;
            if(DB::table('rework_records')->where('company_id',$companyId)->where('bundle_id',$bundle->id)->whereNull('resolved_at')->exists())return null;
            $scan=ProductionScan::withoutGlobalScopes()->where('company_id',$companyId)->where('bundle_id',$bundle->id)->where('stage','FINISHING')->orderByDesc('scanned_at')->orderByDesc('id')->first();
            if(!$scan||$scan->direction!=='OUT')return null;
            return ['bundle_no'=>$bundle->bundle_no,'qty'=>(float)$bundle->qty,'production_order_id'=>$bundle->production_order_id,'source_scan_id'=>$scan->id,'source_operation_id'=>$scan->operation_id,'scanned_at'=>$scan->scanned_at];
        })->filter()->values()->all();
    }

    public function completeFinishing(int $companyId, string $bundleNo, User $user): FinishingOutput
    {
        return DB::transaction(function()use($companyId,$bundleNo,$user){
            $this->access($user,$companyId);
            $bundle=Bundle::withoutGlobalScopes()->where('company_id',$companyId)->where('bundle_no',$bundleNo)->where('status','ACTIVE')->lockForUpdate()->first();
            if(!$bundle||$bundle->current_stage!=='FINISHING')throw new RuntimeException('Bundle tidak eligible untuk completion Finishing.');
            if(FinishingOutput::withoutGlobalScopes()->where('company_id',$companyId)->where('bundle_id',$bundle->id)->exists())throw new RuntimeException('Finishing Output bundle sudah tercatat.');
            if(DB::table('rework_records')->where('company_id',$companyId)->where('bundle_id',$bundle->id)->whereNull('resolved_at')->exists())throw new RuntimeException('Finishing Output diblokir karena bundle masih memiliki rework terbuka.');
            $scan=ProductionScan::withoutGlobalScopes()->where('company_id',$companyId)->where('bundle_id',$bundle->id)->where('stage','FINISHING')->orderByDesc('scanned_at')->orderByDesc('id')->lockForUpdate()->first();
            if(!$scan||$scan->direction!=='OUT')throw new RuntimeException('Finishing Output memerlukan latest Finishing scan OUT.');
            $output=FinishingOutput::create(['company_id'=>$companyId,'production_order_id'=>$bundle->production_order_id,'bundle_id'=>$bundle->id,'source_scan_id'=>$scan->id,'qty'=>$scan->qty??$bundle->qty,'completed_at'=>$scan->scanned_at,'created_by'=>$user->id,'created_at'=>now()]);
            $bundle->update(['current_stage'=>'QC']);
            $remaining=Bundle::withoutGlobalScopes()->where('company_id',$companyId)->where('production_order_id',$bundle->production_order_id)->whereIn('status',['ACTIVE','REWORK'])->where('current_stage','FINISHING')->exists();
            if(!$remaining)ProductionOrder::withoutGlobalScopes()->where('company_id',$companyId)->whereKey($bundle->production_order_id)->where('status','FINISHING')->update(['status'=>'QC','updated_by'=>$user->id]);
            $this->audit->record('create',$output,after:['bundle_no'=>$bundleNo,'source_scan_id'=>$scan->id,'qty'=>(float)$output->qty,'named_measure'=>'FINISHING_OUT']);
            return $output->load(['bundle','sourceScan.operation','productionOrder']);
        });
    }

    public function daily(int $companyId, ?int $lineId, ?string $date): mixed
    {
        $query=LineOutput::with(['line','productionOrder'])->where('company_id',$companyId);
        if($lineId)$query->where('line_id',$lineId);if($date)$query->whereDate('output_date',$date);
        return $query->orderByDesc('output_date')->orderBy('line_id')->get();
    }

    private function access(User $user,int $companyId):void{if((int)$user->company_id!==$companyId&&!$user->companies()->whereKey($companyId)->exists())throw new RuntimeException('User tidak memiliki akses ke company Shop Floor output.');}
}
