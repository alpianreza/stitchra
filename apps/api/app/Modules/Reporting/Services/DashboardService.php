<?php

namespace Modules\Reporting\Services;

use Illuminate\Support\Facades\DB;

/** Dashboard KPI manajemen — 1 endpoint agregat, company-scoped (BR-011) */
class DashboardService
{
    public function kpis(int $companyId, int $userId): array
    {
        $today = now()->toDateString();

        // Open order value (SO aktif: CONFIRMED + IN_PROGRESS)
        $openOrders = DB::table('sales_orders')
            ->where('sales_orders.company_id', $companyId)
            ->whereIn('sales_orders.status', ['CONFIRMED', 'IN_PROGRESS'])
            ->whereNull('sales_orders.deleted_at')
            ->leftJoin('sales_order_lines', 'sales_order_lines.sales_order_id', '=', 'sales_orders.id')
            ->selectRaw('COUNT(DISTINCT sales_orders.id) as count, COALESCE(SUM(sales_order_lines.qty * sales_order_lines.price), 0) as value')
            ->first();

        // MO aktif per status
        $moByStatus = DB::table('production_orders')
            ->where('company_id', $companyId)
            ->whereNotIn('status', ['CLOSED', 'CANCELLED'])
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')->all();

        // Output hari ini (scan OUT)
        $todayOutput = (float) DB::table('production_scans')
            ->where('production_scans.company_id', $companyId)
            ->where('direction', 'OUT')
            ->whereDate('scanned_at', $today)
            ->join('bundles', 'bundles.id', '=', 'production_scans.bundle_id')
            ->sum('bundles.qty');

        // WIP pcs (bundle aktif)
        $wip = (float) DB::table('bundles')
            ->where('company_id', $companyId)->where('status', 'ACTIVE')
            ->sum('qty');

        // QC pass rate 7 hari (FINAL inspections)
        $qc = DB::table('qc_inspections')
            ->where('company_id', $companyId)
            ->where('stage', 'FINAL')
            ->where('created_at', '>=', now()->subDays(7))
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN verdict = 'PASS' THEN 1 ELSE 0 END) as pass")
            ->first();
        $qcPassRate = $qc->total > 0 ? round($qc->pass / $qc->total * 100, 1) : null;

        // Pending approval untuk user ini (role match step aktif)
        $pendingApprovals = DB::table('approval_requests')
            ->where('approval_requests.company_id', $companyId)
            ->where('approval_requests.status', 'PENDING')
            ->join('approval_flow_steps', function ($j) {
                $j->on('approval_flow_steps.flow_id', '=', 'approval_requests.flow_id')
                  ->whereColumn('approval_flow_steps.step_no', 'approval_requests.current_step');
            })
            ->join('user_roles', 'user_roles.role_id', '=', 'approval_flow_steps.role_id')
            ->where('user_roles.user_id', $userId)
            ->count();

        // Pengiriman overdue (SO aktif dengan ex_factory_date lewat)
        $overdueDeliveries = DB::table('sales_orders')
            ->where('company_id', $companyId)
            ->whereIn('status', ['CONFIRMED', 'IN_PROGRESS'])
            ->whereNull('deleted_at')
            ->whereNotNull('ex_factory_date')
            ->whereDate('ex_factory_date', '<', $today)
            ->count();

        // Nilai stok (on_hand × avg_cost)
        $stockValue = (float) DB::table('stock_balances')
            ->where('company_id', $companyId)
            ->selectRaw('COALESCE(SUM(on_hand * COALESCE(avg_cost, 0)), 0) as value')
            ->value('value');

        return [
            'open_orders' => ['count' => (int) $openOrders->count, 'value' => (float) $openOrders->value],
            'mo_by_status' => $moByStatus,
            'today_output_pcs' => $todayOutput,
            'wip_pcs' => $wip,
            'qc_pass_rate_7d_pct' => $qcPassRate,
            'pending_my_approvals' => (int) $pendingApprovals,
            'overdue_deliveries' => (int) $overdueDeliveries,
            'stock_value' => $stockValue,
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
