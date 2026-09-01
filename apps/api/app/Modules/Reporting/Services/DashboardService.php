<?php

namespace Modules\Reporting\Services;

use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function kpis(int $companyId,int $userId):array
    {
        $today=now()->toDateString();
        $open=DB::table('sales_orders')->where('sales_orders.company_id',$companyId)->whereIn('sales_orders.status',['CONFIRMED','IN_PROGRESS'])->whereNull('sales_orders.deleted_at')->leftJoin('sales_order_lines','sales_order_lines.sales_order_id','=','sales_orders.id')->selectRaw('COUNT(DISTINCT sales_orders.id) count,COALESCE(SUM(sales_order_lines.qty*sales_order_lines.price),0) value')->first();
        $mo=DB::table('production_orders')->where('company_id',$companyId)->whereNotIn('status',['CLOSED','CANCELLED'])->selectRaw('status,COUNT(*) count')->groupBy('status')->pluck('count','status')->all();
        $output=(float)DB::table('production_scans')->where('production_scans.company_id',$companyId)->where('production_scans.stage','SEWING')->where('production_scans.direction','OUT')->whereDate('production_scans.scanned_at',$today)->join('bundles','bundles.id','=','production_scans.bundle_id')->join('production_orders','production_orders.id','=','production_scans.production_order_id')->join('routing_operations as ro',function($j){$j->on('ro.routing_version_id','=','production_orders.routing_version_id')->on('ro.operation_id','=','production_scans.operation_id');})->whereNotExists(fn($q)=>$q->selectRaw('1')->from('routing_operations as later')->whereColumn('later.routing_version_id','ro.routing_version_id')->whereColumn('later.seq','>','ro.seq'))->sum('bundles.qty');
        $wip=(float)DB::table('bundles')->where('bundles.company_id',$companyId)->whereIn('bundles.status',['ACTIVE','REWORK'])->sum('bundles.qty');
        $latestFinal=DB::table('qc_inspections')->selectRaw('MAX(id) id')->where('company_id',$companyId)->where('stage','FINAL')->where('created_at','>=',now()->subDays(7))->groupBy('production_order_id');
        $qc=DB::query()->fromSub($latestFinal,'latest')->join('qc_inspections','qc_inspections.id','=','latest.id')->selectRaw("COUNT(*) total,SUM(CASE WHEN verdict='PASS' THEN 1 ELSE 0 END) pass")->first();$rate=$qc->total>0?round($qc->pass/$qc->total*100,1):null;
        $pending=DB::table('approval_requests')->where('approval_requests.company_id',$companyId)->where('approval_requests.status','PENDING')->join('approval_flow_steps',function($j){$j->on('approval_flow_steps.flow_id','=','approval_requests.flow_id')->whereColumn('approval_flow_steps.step_no','approval_requests.current_step');})->join('roles',function($j)use($companyId){$j->on('roles.id','=','approval_flow_steps.role_id')->where('roles.company_id',$companyId)->whereNull('roles.deleted_at');})->join('user_roles',function($j)use($userId){$j->on('user_roles.role_id','=','roles.id')->where('user_roles.user_id',$userId);})->distinct()->count('approval_requests.id');
        $overdue=DB::table('sales_orders')->where('company_id',$companyId)->whereIn('status',['CONFIRMED','IN_PROGRESS'])->whereNull('deleted_at')->whereNotNull('ex_factory_date')->whereDate('ex_factory_date','<',$today)->count();
        $stock=(float)DB::table('stock_balances')->where('company_id',$companyId)->selectRaw('COALESCE(SUM(on_hand*COALESCE(avg_cost,0)),0) value')->value('value');
        return['open_orders'=>['count'=>(int)$open->count,'value'=>(float)$open->value],'mo_by_status'=>$mo,'today_output_pcs'=>$output,'wip_pcs'=>$wip,'qc_pass_rate_7d_pct'=>$rate,'pending_my_approvals'=>(int)$pending,'overdue_deliveries'=>(int)$overdue,'stock_value'=>$stock,'generated_at'=>now()->toIso8601String()];
    }
}
