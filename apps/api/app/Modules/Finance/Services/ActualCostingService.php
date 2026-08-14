<?php

namespace Modules\Finance\Services;

use Modules\Inventory\Models\StockLedger;
use Modules\MasterData\Models\LineCostRate;
use Modules\MasterData\Models\OverheadRate;
use Modules\ProductDev\Models\CostSheet;
use Modules\Production\Models\ProductionOrder;
use Modules\ShopFloor\Models\ProductionScan;
use RuntimeException;

/**
 * BR-080/081: costing AKTUAL per MO, dibanding standard (cost sheet APPROVED — BR-100).
 *
 * Komponen aktual:
 * - Material : Σ qty issue (material_issues MO) × avg_cost saldo material/gudang saat ini
 *              (moving average — BR-005). Bila ledger menyimpan unit_cost, itu yang dipakai.
 * - Labor    : output (scan OUT) × total SAM (routing snapshot MO) × cost-per-minute line (periode).
 * - Overhead : output × total SAM × OH rate periode (BR-009).
 * - Subcon   : Σ subcon_fees MO (BR-080).
 * Variance = aktual − standard per komponen (standard per-pcs × output).
 */
class ActualCostingService
{
    public function computeForMo(ProductionOrder $mo, ?string $period = null): array
    {
        $period = $period ?? ($mo->created_at?->format('Y-m') ?? now()->format('Y-m'));

        // Output aktual = Σ qty bundle yang scan OUT (distinct bundle, sekali per bundle)
        $output = (float) ProductionScan::where('production_order_id', $mo->id)
            ->where('direction', 'OUT')
            ->distinct('bundle_id')
            ->join('bundles', 'bundles.id', '=', 'production_scans.bundle_id')
            ->sum('bundles.qty');

        if ($output <= 0) {
            $output = (float) $mo->qty_produced;
        }
        if ($output <= 0) {
            throw new RuntimeException("MO {$mo->doc_no} belum punya output (scan OUT / qty_produced).");
        }

        // --- Material aktual (BR-041: fabric aktual, trim backflush — keduanya di material_issues)
        $materialCost = (float) StockLedger::withoutGlobalScopes()
            ->where('stock_ledger.movement_type', 'MATERIAL_ISSUE')
            ->where('stock_ledger.source_document_type', 'material_issues')
            ->join('material_issues', 'material_issues.id', '=', 'stock_ledger.source_document_id')
            ->where('material_issues.production_order_id', $mo->id)
            ->selectRaw('COALESCE(SUM(stock_ledger.qty_out * COALESCE(stock_ledger.unit_cost, (SELECT sb.avg_cost FROM stock_balances sb WHERE sb.material_id = stock_ledger.material_id AND sb.warehouse_id = stock_ledger.warehouse_id LIMIT 1))), 0) as cost')
            ->value('cost');

        // --- Labor & OH (BR-009)
        $totalSam = (float) ($mo->routingVersion?->total_sam ?? 0);
        $lineRate = $mo->line_id
            ? (float) (LineCostRate::withoutGlobalScopes()
                ->where('company_id', $mo->company_id)->where('line_id', $mo->line_id)->where('period', $period)
                ->value('cost_per_minute') ?? 0)
            : 0.0;
        $ohRate = (float) (OverheadRate::withoutGlobalScopes()
            ->where('company_id', $mo->company_id)->where('period', $period)
            ->value('rate_per_minute') ?? 0);

        $laborCost = round($output * $totalSam * $lineRate, 4);
        $ohCost = round($output * $totalSam * $ohRate, 4);

        // --- Subcon (BR-080)
        $subconCost = (float) \Modules\Subcon\Models\SubconFee::whereHas('subconOrder', fn ($q) => $q->where('production_order_id', $mo->id))
            ->sum('total_fee');

        $totalActual = round($materialCost + $laborCost + $ohCost + $subconCost, 4);

        // --- Standard (cost sheet APPROVED — BR-100) × output
        $standard = CostSheet::withoutGlobalScopes()
            ->where('company_id', $mo->company_id)->where('style_id', $mo->style_id)->where('status', 'APPROVED')
            ->latest('id')->first();

        $variance = null;
        if ($standard !== null) {
            $stdMaterial = ((float) $standard->fabric_cost + (float) $standard->trim_cost) * $output;
            $stdLabor = (float) $standard->cm_cost * $output;
            $stdOh = (float) $standard->overhead_cost * $output;
            $stdSubcon = (float) $standard->subcon_cost * $output;
            $stdTotal = $stdMaterial + $stdLabor + $stdOh + $stdSubcon;

            $variance = [
                'material' => round($materialCost - $stdMaterial, 4),
                'labor' => round($laborCost - $stdLabor, 4),
                'overhead' => round($ohCost - $stdOh, 4),
                'subcon' => round($subconCost - $stdSubcon, 4),
                'total' => round($totalActual - $stdTotal, 4),
                'standard_total' => round($stdTotal, 4),
                'cost_sheet' => $standard->doc_no,
            ];
        }

        return [
            'mo' => $mo->doc_no,
            'period' => $period,
            'output_pcs' => $output,
            'actual' => [
                'material' => round($materialCost, 4),
                'labor' => $laborCost,
                'overhead' => $ohCost,
                'subcon' => round($subconCost, 4),
                'total' => $totalActual,
                'per_pcs' => round($totalActual / $output, 4),
            ],
            'variance_vs_standard' => $variance,   // null bila belum ada standard cost approved
        ];
    }
}
