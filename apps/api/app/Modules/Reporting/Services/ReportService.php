<?php

namespace Modules\Reporting\Services;

use Illuminate\Support\Facades\DB;
use Modules\Finance\Services\BepService;
use Modules\Production\Models\ProductionOrder;
use RuntimeException;

/**
 * Registry 8 report inti (ROADMAP Phase 9). Semua read-only, agregasi SQL,
 * company-scoped. Return shape: ['columns' => [...], 'rows' => [...]]
 */
class ReportService
{
    public function __construct(private BepService $bep) {}

    /** @return string[] daftar report yang tersedia */
    public function available(): array
    {
        return [
            'order_status', 'wip_summary', 'production_efficiency', 'qc_summary',
            'stock_aging', 'consumption_variance', 'otd', 'bep_position',
        ];
    }

    public function run(int $companyId, string $report, array $params = []): array
    {
        return match ($report) {
            'order_status' => $this->orderStatus($companyId),
            'wip_summary' => $this->wipSummary($companyId),
            'production_efficiency' => $this->productionEfficiency($companyId, $params),
            'qc_summary' => $this->qcSummary($companyId, $params),
            'stock_aging' => $this->stockAging($companyId),
            'consumption_variance' => $this->consumptionVariance($companyId),
            'otd' => $this->otd($companyId),
            'bep_position' => $this->bepPosition($companyId, $params),
            default => throw new RuntimeException("Report [{$report}] tidak dikenal. Tersedia: ".implode(', ', $this->available())),
        };
    }

    /** 1. Order status — lifecycle SO + nilai */
    private function orderStatus(int $companyId): array
    {
        $rows = DB::table('sales_orders')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->leftJoin('sales_order_lines', 'sales_order_lines.sales_order_id', '=', 'sales_orders.id')
            ->leftJoin('customers', 'customers.id', '=', 'sales_orders.customer_id')
            ->selectRaw('sales_orders.doc_no, customers.name as customer, sales_orders.status, sales_orders.order_date, sales_orders.ex_factory_date, SUM(sales_order_lines.qty) as total_qty, SUM(sales_order_lines.qty * sales_order_lines.price) as total_value')
            ->groupBy('sales_orders.id', 'sales_orders.doc_no', 'customers.name', 'sales_orders.status', 'sales_orders.order_date', 'sales_orders.ex_factory_date')
            ->orderByDesc('sales_orders.id')
            ->get()->all();

        return ['columns' => ['doc_no', 'customer', 'status', 'order_date', 'ex_factory_date', 'total_qty', 'total_value'], 'rows' => $rows];
    }

    /** 2. WIP per MO per stage (BR-063) */
    private function wipSummary(int $companyId): array
    {
        $rows = DB::table('bundles')
            ->where('bundles.company_id', $companyId)->where('bundles.status', 'ACTIVE')
            ->join('production_orders', 'production_orders.id', '=', 'bundles.production_order_id')
            ->join('styles', 'styles.id', '=', 'production_orders.style_id')
            ->selectRaw('production_orders.doc_no as mo, styles.style_no, bundles.current_stage, COUNT(*) as bundles, SUM(bundles.qty) as pcs')
            ->groupBy('production_orders.doc_no', 'styles.style_no', 'bundles.current_stage')
            ->orderBy('production_orders.doc_no')
            ->get()->all();

        return ['columns' => ['mo', 'style_no', 'current_stage', 'bundles', 'pcs'], 'rows' => $rows];
    }

    /** 3. Production efficiency per line per hari (output pcs × SAM vs kapasitas) */
    private function productionEfficiency(int $companyId, array $params): array
    {
        $date = $params['date'] ?? now()->toDateString();

        $rows = DB::table('production_scans')
            ->where('production_scans.company_id', $companyId)
            ->where('production_scans.direction', 'OUT')
            ->whereDate('production_scans.scanned_at', $date)
            ->join('bundles', 'bundles.id', '=', 'production_scans.bundle_id')
            ->join('production_orders', 'production_orders.id', '=', 'production_scans.production_order_id')
            ->join('routing_versions', 'routing_versions.id', '=', 'production_orders.routing_version_id')
            ->join('lines', 'lines.id', '=', 'production_scans.line_id')
            ->selectRaw('lines.code as line, production_scans.stage, SUM(bundles.qty) as pcs_output, SUM(bundles.qty * routing_versions.total_sam) as sam_earned, MAX(lines.capacity_std) as capacity_std')
            ->groupBy('lines.code', 'production_scans.stage')
            ->orderBy('lines.code')
            ->get()->all();

        return ['columns' => ['line', 'stage', 'pcs_output', 'sam_earned', 'capacity_std'], 'rows' => $rows];
    }

    /** 4. QC summary — pass rate + Pareto defect (BR-072) */
    private function qcSummary(int $companyId, array $params): array
    {
        $verdicts = DB::table('qc_inspections')
            ->where('company_id', $companyId)
            ->selectRaw("stage, verdict, COUNT(*) as total")
            ->groupBy('stage', 'verdict')
            ->get()->all();

        $pareto = DB::table('qc_inspection_lines')
            ->join('qc_inspections', 'qc_inspections.id', '=', 'qc_inspection_lines.qc_inspection_id')
            ->join('defect_library', 'defect_library.id', '=', 'qc_inspection_lines.defect_id')
            ->where('qc_inspections.company_id', $companyId)
            ->selectRaw('defect_library.name, defect_library.severity, SUM(qc_inspection_lines.qty) as occurrences')
            ->groupBy('defect_library.name', 'defect_library.severity')
            ->orderByDesc('occurrences')
            ->limit(20)
            ->get()->all();

        return ['columns' => ['name', 'severity', 'occurrences'], 'rows' => $pareto, 'verdict_summary' => $verdicts];
    }

    /** 5. Stock aging — umur stok dari penerimaan pertama yang masih bersaldo */
    private function stockAging(int $companyId): array
    {
        $rows = DB::table('stock_balances')
            ->where('stock_balances.company_id', $companyId)
            ->where('stock_balances.on_hand', '>', 0)
            ->whereNotNull('stock_balances.material_id')
            ->join('materials', 'materials.id', '=', 'stock_balances.material_id')
            ->join('warehouses', 'warehouses.id', '=', 'stock_balances.warehouse_id')
            ->selectRaw("materials.code, materials.name, warehouses.code as warehouse, stock_balances.on_hand, stock_balances.reserved, stock_balances.quality_hold, stock_balances.avg_cost, (stock_balances.on_hand * COALESCE(stock_balances.avg_cost,0)) as stock_value, DATEDIFF(CURDATE(), stock_balances.created_at) as age_days")
            ->orderByDesc('stock_value')
            ->get()->all();

        return ['columns' => ['code', 'name', 'warehouse', 'on_hand', 'reserved', 'quality_hold', 'avg_cost', 'stock_value', 'age_days'], 'rows' => $rows];
    }

    /** 6. Consumption variance — BOM estimated vs actual per style (BR-031) */
    private function consumptionVariance(int $companyId): array
    {
        $rows = DB::table('bom_lines')
            ->join('bom_versions', 'bom_versions.id', '=', 'bom_lines.bom_version_id')
            ->join('boms', 'boms.id', '=', 'bom_versions.bom_id')
            ->join('styles', 'styles.id', '=', 'boms.style_id')
            ->join('materials', 'materials.id', '=', 'bom_lines.material_id')
            ->where('boms.company_id', $companyId)
            ->where('bom_versions.status', 'APPROVED')
            ->whereNotNull('bom_lines.consumption_actual')
            ->selectRaw('styles.style_no, materials.code as material, bom_lines.qty_per_pcs, bom_lines.wastage_pct, bom_lines.shrinkage_pct, bom_lines.consumption_actual, ROUND((bom_lines.consumption_actual - bom_lines.qty_per_pcs) / bom_lines.qty_per_pcs * 100, 2) as variance_pct')
            ->orderBy('styles.style_no')
            ->get()->all();

        return ['columns' => ['style_no', 'material', 'qty_per_pcs', 'wastage_pct', 'shrinkage_pct', 'consumption_actual', 'variance_pct'], 'rows' => $rows];
    }

    /** 7. OTD — on-time delivery: ship_date vs ex_factory_date */
    private function otd(int $companyId): array
    {
        $rows = DB::table('shipments')
            ->where('shipments.company_id', $companyId)->where('shipments.status', 'SHIPPED')
            ->join('sales_orders', 'sales_orders.id', '=', 'shipments.sales_order_id')
            ->selectRaw("shipments.doc_no, sales_orders.doc_no as so, sales_orders.ex_factory_date, shipments.ship_date, DATEDIFF(shipments.ship_date, sales_orders.ex_factory_date) as days_late")
            ->orderByDesc('shipments.ship_date')
            ->get()->all();

        return ['columns' => ['doc_no', 'so', 'ex_factory_date', 'ship_date', 'days_late'], 'rows' => $rows];
    }

    /** 8. BEP position — volume aktual (shipped) vs BEP per style (BR-104) */
    private function bepPosition(int $companyId, array $params): array
    {
        $fixedCostShare = (float) ($params['fixed_cost_share'] ?? 0);

        $styles = DB::table('cost_sheets')
            ->where('company_id', $companyId)->where('status', 'APPROVED')->where('fob_price', '>', 0)
            ->join('styles', 'styles.id', '=', 'cost_sheets.style_id')
            ->select('styles.id', 'styles.style_no')
            ->get();

        $rows = [];
        foreach ($styles as $style) {
            $shipped = (float) DB::table('shipment_lines')
                ->join('shipments', 'shipments.id', '=', 'shipment_lines.shipment_id')
                ->where('shipments.company_id', $companyId)->where('shipments.status', 'SHIPPED')
                ->where('shipment_lines.style_id', $style->id)
                ->sum('shipment_lines.qty_shipped');

            $bep = $fixedCostShare > 0
                ? $this->bep->forStyle($companyId, $style->id, $fixedCostShare)
                : null;

            $rows[] = [
                'style_no' => $style->style_no,
                'qty_shipped' => $shipped,
                'bep_qty' => $bep['bep_qty'] ?? null,
                'position' => $bep ? ($shipped >= $bep['bep_qty'] ? 'ABOVE_BEP' : 'BELOW_BEP') : 'NO_FIXED_COST_INPUT',
            ];
        }

        return ['columns' => ['style_no', 'qty_shipped', 'bep_qty', 'position'], 'rows' => $rows];
    }

    /** Export CSV sederhana dari hasil report */
    public function toCsv(array $report): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $report['columns']);
        foreach ($report['rows'] as $row) {
            fputcsv($out, array_map(fn ($c) => $row->{$c} ?? null, $report['columns']));
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
