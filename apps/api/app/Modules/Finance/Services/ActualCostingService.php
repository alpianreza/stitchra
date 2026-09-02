<?php

namespace Modules\Finance\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\MasterData\Models\LineCostRate;
use Modules\MasterData\Models\OverheadRate;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Services\StandardCostSnapshotService;
use RuntimeException;

/**
 * BR-009/100/120 actual-cost read model.
 *
 * This service does not create a parallel costing ledger. It reads immutable
 * operational sources and explicitly marks every authority that is incomplete.
 */
class ActualCostingService
{
    public function __construct(private StandardCostSnapshotService $snapshots) {}

    public function computeForMo(
        ProductionOrder $mo,
        ?string $period = null,
        ?int $companyId = null,
    ): array {
        $companyId ??= (int) $mo->company_id;
        if ($companyId !== (int) $mo->company_id) {
            throw new RuntimeException('Production Order bukan milik company aktif.');
        }

        $period ??= $mo->created_at?->format('Y-m') ?? now()->format('Y-m');
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new RuntimeException('Period costing wajib format YYYY-MM.');
        }

        return DB::transaction(function () use ($mo, $period, $companyId): array {
            $locked = ProductionOrder::withoutGlobalScopes()
                ->with(['routingVersion.operations.operation', 'style', 'line', 'salesOrder'])
                ->where('company_id', $companyId)
                ->whereKey($mo->id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked = $this->snapshots->forCosting($locked)->fresh([
                'routingVersion.operations.operation', 'style', 'line', 'salesOrder',
            ]);
            $standard = $locked->standard_cost_snapshot;

            $material = $this->materialTrace($locked);
            $production = $this->productionTrace($locked, $period);
            $subcon = $this->subconTrace($locked);

            $labor = $production['labor']['amount'];
            $overhead = $production['overhead']['amount'];
            $materialAmount = $material['defined_net_flow_cost'];
            $subconAmount = $subcon['amount'];
            $definedTotal = $this->sumWhenDefined([
                $materialAmount, $labor, $overhead, $subconAmount,
            ]);

            $standardAmounts = $this->standardAmounts($standard, $production['output']['qty']);
            $variance = $this->variance(
                $standard,
                $standardAmounts,
                [
                    'material' => $materialAmount,
                    'labor' => $labor,
                    'overhead' => $overhead,
                    'subcon' => $subconAmount,
                    'other' => null,
                ],
                (bool) $production['output']['authoritative'],
            );

            $glPeriod = DB::table('gl_periods')
                ->where('company_id', $companyId)
                ->where('period', $period)
                ->first();
            $company = DB::table('companies')->where('id', $companyId)->first();

            $overallStatus = collect([
                $material['status'],
                $production['status'],
                $subcon['status'],
            ])->contains(fn (string $status) => $status !== 'DEFINED') ? 'PARTIAL' : 'DEFINED';

            return [
                'mo' => $locked->doc_no,
                'production_order' => [
                    'id' => $locked->id,
                    'doc_no' => $locked->doc_no,
                    'status' => $locked->status,
                    'style' => $locked->style?->only(['id', 'style_no']),
                    'sales_order' => $locked->salesOrder?->only(['id', 'doc_no']),
                    'line' => $locked->line?->only(['id', 'code', 'name']),
                    'qty_planned' => (float) $locked->qty_planned,
                    'legacy_qty_produced' => (float) $locked->qty_produced,
                ],
                'period' => $period,
                'period_control' => [
                    'gl_status' => $glPeriod?->status ?? 'NOT_CONFIGURED',
                    'behavior' => 'READ_ONLY_COMPUTATION',
                    'recalculation' => 'NOT_DEFINED',
                    'close_mutation' => 'NOT_APPLICABLE_NO_COST_WRITE',
                ],
                'currency' => [
                    'code' => $company?->base_currency,
                    'authority' => 'COMPANY_BASE_CURRENCY',
                    'source_transaction_fx' => 'NOT_DEFINED_FOR_ACTUAL_MO_COST',
                ],
                'output_pcs' => $production['output']['qty'],
                'calculation' => [
                    'mode' => 'COMPUTED_READ_ONLY',
                    'persisted' => false,
                    'status' => $overallStatus,
                    'actual_cost_document_authority' => 'PRODUCTION_ORDER_COMPUTED_VIEW',
                    'actual_costs_table' => 'NOT_IMPLEMENTED',
                    'lifecycle' => 'NOT_DEFINED',
                ],
                'actual' => [
                    'material' => $materialAmount,
                    'labor' => $labor,
                    'machine' => null,
                    'overhead' => $overhead,
                    'subcon' => $subconAmount,
                    'other' => null,
                    'defined_total' => $definedTotal,
                    'total' => $definedTotal,
                    'per_pcs' => null,
                ],
                'components' => [
                    'material' => $material,
                    'production' => $production,
                    'subcon' => $subcon,
                    'other' => [
                        'amount' => null,
                        'status' => 'NOT_DEFINED',
                        'sources' => [],
                    ],
                ],
                'variance_vs_standard' => $variance,
                'standard' => [
                    'snapshot' => $standard,
                    'amounts_for_output' => $standardAmounts,
                    'snapshot_hash' => $locked->standard_cost_snapshot_hash,
                    'snapshot_source' => $locked->standard_cost_snapshot_source,
                ],
                'authorities' => [
                    'material_cost' => $material['authority'],
                    'production_output' => $production['output']['authority'],
                    'labor' => 'BR-009 — output × total SAM × configured line cost/minute',
                    'machine_cost' => 'NOT_DEFINED',
                    'overhead' => 'BR-009 — output × total SAM × OH rate/minute',
                    'subcon' => 'BR-091 — subcon_fees linked through Job Work Order',
                    'wip_valuation' => 'NOT_DEFINED',
                    'fg_valuation' => 'NOT_DEFINED',
                    'cost_per_unit_denominator' => 'NOT_DEFINED',
                    'variance' => $variance['authority'],
                    'costing_period' => 'RATE_LOOKUP_PERIOD_ONLY; RECALCULATION/CLOSE AUTHORITY NOT_DEFINED',
                    'rounding' => 'MONEY DECIMAL(19,4); RATE DECIMAL(18,6)',
                ],
                'lineage' => [
                    'forward' => 'MO → material issue/return ledger + production output/rates + subcon fees → defined actual components',
                    'reverse' => 'component → source transaction/line → operational document → Production Order',
                    'wip_fg_bridge' => 'NOT_DEFINED',
                ],
            ];
        });
    }

    public function lineageForMo(
        ProductionOrder $mo,
        ?string $period = null,
        ?int $companyId = null,
    ): array {
        return $this->computeForMo($mo, $period, $companyId);
    }

    private function materialTrace(ProductionOrder $mo): array
    {
        $issueRows = DB::table('stock_ledger as ledger')
            ->join('material_issues as issue', function ($join): void {
                $join->on('issue.id', '=', 'ledger.source_document_id')
                    ->where('ledger.source_document_type', '=', 'material_issues');
            })
            ->leftJoin('material_issue_lines as issue_line', 'issue_line.id', '=', 'ledger.source_document_line_id')
            ->leftJoin('materials as material', 'material.id', '=', 'ledger.material_id')
            ->leftJoin('warehouses as warehouse', 'warehouse.id', '=', 'ledger.warehouse_id')
            ->leftJoin('uoms as uom', 'uom.id', '=', 'ledger.uom_id')
            ->leftJoin('fabric_rolls as roll', 'roll.id', '=', 'ledger.roll_id')
            ->where('ledger.company_id', $mo->company_id)
            ->where('issue.company_id', $mo->company_id)
            ->where('issue.production_order_id', $mo->id)
            ->where('ledger.movement_type', 'MATERIAL_ISSUE')
            ->orderBy('ledger.id')
            ->select([
                'ledger.id as ledger_id', 'issue.id as issue_id', 'issue.doc_no as issue_doc_no',
                'issue.mode', 'issue_line.id as issue_line_id', 'ledger.material_id',
                'material.code as material_code', 'material.name as material_name',
                'ledger.warehouse_id', 'warehouse.code as warehouse_code', 'ledger.location_id',
                'ledger.lot_no', 'ledger.roll_id', 'roll.roll_no', 'ledger.ownership',
                'ledger.qty_out', 'ledger.uom_id', 'uom.code as uom_code',
                'ledger.unit_cost', 'ledger.total_cost', 'ledger.created_at',
            ])
            ->get();

        $returnRows = DB::table('stock_ledger as ledger')
            ->join('fabric_returns as fabric_return', function ($join): void {
                $join->on('fabric_return.id', '=', 'ledger.source_document_id')
                    ->where('ledger.source_document_type', '=', 'fabric_returns');
            })
            ->leftJoin('materials as material', 'material.id', '=', 'ledger.material_id')
            ->leftJoin('warehouses as warehouse', 'warehouse.id', '=', 'ledger.warehouse_id')
            ->leftJoin('uoms as uom', 'uom.id', '=', 'ledger.uom_id')
            ->leftJoin('fabric_rolls as roll', 'roll.id', '=', 'ledger.roll_id')
            ->where('ledger.company_id', $mo->company_id)
            ->where('fabric_return.company_id', $mo->company_id)
            ->where('fabric_return.production_order_id', $mo->id)
            ->where('ledger.movement_type', 'PRODUCTION_RETURN')
            ->orderBy('ledger.id')
            ->select([
                'ledger.id as ledger_id', 'fabric_return.id as return_id',
                'fabric_return.doc_no as return_doc_no', 'ledger.material_id',
                'material.code as material_code', 'material.name as material_name',
                'ledger.warehouse_id', 'warehouse.code as warehouse_code', 'ledger.location_id',
                'ledger.lot_no', 'ledger.roll_id', 'roll.roll_no', 'ledger.ownership',
                'ledger.qty_in', 'ledger.uom_id', 'uom.code as uom_code',
                'ledger.unit_cost', 'ledger.total_cost', 'ledger.created_at',
            ])
            ->get();

        $companyIssues = $issueRows->where('ownership', 'COMPANY')->values();
        $companyReturns = $returnRows->where('ownership', 'COMPANY')->values();
        $missingIssueValuation = $this->missingValuation($companyIssues);
        $missingReturnValuation = $this->missingValuation($companyReturns);

        $grossIssueCost = $missingIssueValuation ? null : round((float) $companyIssues->sum('total_cost'), 4);
        $returnCost = $missingReturnValuation ? null : round((float) $companyReturns->sum('total_cost'), 4);
        $net = $grossIssueCost !== null && $returnCost !== null
            ? round($grossIssueCost - $returnCost, 4)
            : null;

        $authority = $net === null
            ? 'PARTIAL — BR-005 ledger valuation missing on one or more historical source rows'
            : 'BR-005/031/042 — valued MATERIAL_ISSUE less valued PRODUCTION_RETURN';

        return [
            'amount' => $net,
            'defined_net_flow_cost' => $net,
            'gross_issue_cost' => $grossIssueCost,
            'leftover_return_cost' => $returnCost,
            'status' => $net === null ? 'PARTIAL' : 'DEFINED',
            'authority' => $authority,
            'wastage_value' => null,
            'wastage_authority' => 'NOT_DEFINED_AS_SEPARATE_COST_SOURCE; no allocation invented',
            'marker_vs_lay_consumption_authority' => 'NOT_DEFINED',
            'buyer_owned_excluded_from_valuation' => true,
            'issues' => $issueRows->map(fn ($row) => (array) $row)->values(),
            'returns' => $returnRows->map(fn ($row) => (array) $row)->values(),
        ];
    }

    private function productionTrace(ProductionOrder $mo, string $period): array
    {
        $finalRoutingOperation = $mo->routingVersion?->operations?->sortByDesc('seq')->first();
        $scans = collect();
        $output = null;
        $outputAuthority = 'NOT_AVAILABLE';
        $outputAuthoritative = false;

        if ($finalRoutingOperation) {
            $scans = DB::table('production_scans as scan')
                ->join('bundles as bundle', 'bundle.id', '=', 'scan.bundle_id')
                ->leftJoin('operations as operation', 'operation.id', '=', 'scan.operation_id')
                ->leftJoin('lines as production_line', 'production_line.id', '=', 'scan.line_id')
                ->leftJoin('employees as employee', 'employee.id', '=', 'scan.employee_id')
                ->where('scan.company_id', $mo->company_id)
                ->where('scan.production_order_id', $mo->id)
                ->where('scan.operation_id', $finalRoutingOperation->operation_id)
                ->where('scan.direction', 'OUT')
                ->orderBy('scan.id')
                ->select([
                    'scan.id', 'scan.bundle_id', 'bundle.bundle_no', 'scan.operation_id',
                    'operation.code as operation_code', 'operation.name as operation_name',
                    'scan.stage', 'scan.qty', 'scan.line_id', 'production_line.code as line_code',
                    'scan.employee_id', 'employee.nik as employee_nik', 'scan.scanned_at',
                ])
                ->get();

            $ambiguousBundles = $scans->groupBy('bundle_id')->filter(fn (Collection $rows) => $rows->count() > 1);
            if ($scans->isNotEmpty() && $ambiguousBundles->isEmpty()) {
                $output = round((float) $scans->sum('qty'), 4);
                $outputAuthority = 'FINAL_ROUTING_OPERATION_OUT_SCANS';
                $outputAuthoritative = true;
            } elseif ($ambiguousBundles->isNotEmpty()) {
                $outputAuthority = 'AMBIGUOUS_DUPLICATE_FINAL_OPERATION_OUTPUT';
            }
        }

        if ($output === null && (float) $mo->qty_produced > 0) {
            $output = (float) $mo->qty_produced;
            $outputAuthority = 'LEGACY_QTY_PRODUCED_WRITER_NOT_DEFINED';
        }

        $sam = (float) ($mo->routingVersion?->total_sam ?? 0);
        $lineRate = $mo->line_id
            ? LineCostRate::withoutGlobalScopes()
                ->where('company_id', $mo->company_id)
                ->where('line_id', $mo->line_id)
                ->where('period', $period)
                ->first()
            : null;
        $overheadRate = OverheadRate::withoutGlobalScopes()
            ->where('company_id', $mo->company_id)
            ->where('period', $period)
            ->first();

        $labor = $output !== null && $sam > 0 && $lineRate
            ? round($output * $sam * (float) $lineRate->cost_per_minute, 4)
            : null;
        $overhead = $output !== null && $sam > 0 && $overheadRate
            ? round($output * $sam * (float) $overheadRate->rate_per_minute, 4)
            : null;

        $status = $labor !== null && $overhead !== null && $outputAuthoritative ? 'DEFINED' : 'PARTIAL';

        return [
            'status' => $status,
            'output' => [
                'qty' => $output,
                'authority' => $outputAuthority,
                'authoritative' => $outputAuthoritative,
                'final_routing_operation_id' => $finalRoutingOperation?->operation_id,
                'final_routing_seq' => $finalRoutingOperation?->seq,
                'scans' => $scans->map(fn ($row) => (array) $row)->values(),
            ],
            'routing' => [
                'id' => $mo->routing_version_id,
                'total_sam' => $sam > 0 ? $sam : null,
            ],
            'labor' => [
                'amount' => $labor,
                'formula' => 'output × total_sam × line_cost_rate_per_minute',
                'authority' => 'BR-009',
                'line_rate' => $lineRate ? [
                    'id' => $lineRate->id,
                    'line_id' => $lineRate->line_id,
                    'period' => $lineRate->period,
                    'cost_per_minute' => (float) $lineRate->cost_per_minute,
                ] : null,
            ],
            'machine' => [
                'amount' => null,
                'authority' => 'NOT_DEFINED',
                'sources' => [],
            ],
            'overhead' => [
                'amount' => $overhead,
                'formula' => 'output × total_sam × overhead_rate_per_minute',
                'authority' => 'BR-009',
                'rate' => $overheadRate ? [
                    'id' => $overheadRate->id,
                    'period' => $overheadRate->period,
                    'rate_per_minute' => (float) $overheadRate->rate_per_minute,
                ] : null,
                'department_machine_allocation' => 'NOT_DEFINED',
            ],
        ];
    }

    private function subconTrace(ProductionOrder $mo): array
    {
        $fees = DB::table('subcon_fees as fee')
            ->join('subcon_orders as job_work', 'job_work.id', '=', 'fee.subcon_order_id')
            ->join('suppliers as supplier', 'supplier.id', '=', 'job_work.supplier_id')
            ->leftJoin('subcon_order_lines as job_line', 'job_line.id', '=', 'fee.subcon_order_line_id')
            ->leftJoin('operations as operation', 'operation.id', '=', 'job_work.operation_id')
            ->where('job_work.company_id', $mo->company_id)
            ->where('job_work.production_order_id', $mo->id)
            ->orderBy('fee.id')
            ->select([
                'fee.id as fee_id', 'fee.subcon_order_line_id', 'fee.receipt_reference',
                'fee.return_date', 'fee.qty_returned', 'fee.fee_per_pcs', 'fee.total_fee',
                'job_work.id as job_work_id', 'job_work.doc_no as job_work_doc_no',
                'job_work.status as job_work_status', 'job_work.operation_id',
                'operation.code as operation_code', 'operation.name as operation_name',
                'supplier.id as supplier_id', 'supplier.code as supplier_code',
                'supplier.name as supplier_name',
            ])
            ->get();

        return [
            'amount' => round((float) $fees->sum('total_fee'), 4),
            'status' => 'DEFINED',
            'authority' => 'BR-091',
            'fees' => $fees->map(fn ($row) => (array) $row)->values(),
            'vendor_invoice_ap_match' => 'NOT_IMPLEMENTED',
        ];
    }

    private function missingValuation(Collection $rows): bool
    {
        return $rows->contains(fn ($row) => $row->unit_cost === null || $row->total_cost === null);
    }

    private function sumWhenDefined(array $amounts): ?float
    {
        if (collect($amounts)->contains(fn ($amount) => $amount === null)) {
            return null;
        }
        return round(array_sum($amounts), 4);
    }

    private function standardAmounts(array $standard, ?float $output): ?array
    {
        if ($output === null) {
            return null;
        }
        return [
            'material' => round(((float) $standard['fabric'] + (float) $standard['trim']) * $output, 4),
            'labor' => round((float) $standard['labor'] * $output, 4),
            'overhead' => round((float) $standard['overhead'] * $output, 4),
            'subcon' => round((float) $standard['subcon'] * $output, 4),
            'other' => round((float) $standard['other'] * $output, 4),
        ];
    }

    private function variance(array $standard, ?array $standardAmounts, array $actual, bool $outputAuthoritative): array
    {
        $values = [];
        foreach (['material', 'labor', 'overhead', 'subcon', 'other'] as $component) {
            $values[$component] = $standardAmounts !== null && $actual[$component] !== null
                ? round($actual[$component] - $standardAmounts[$component], 4)
                : null;
        }
        $complete = $outputAuthoritative
            && ! collect($values)->contains(fn ($value) => $value === null);
        $standardTotal = $standardAmounts === null ? null : round(array_sum($standardAmounts), 4);
        $actualTotal = $this->sumWhenDefined(array_values($actual));

        return array_merge($values, [
            'total' => $complete && $actualTotal !== null && $standardTotal !== null
                ? round($actualTotal - $standardTotal, 4)
                : null,
            'standard_total' => $standardTotal,
            'cost_sheet' => $standard['doc_no'],
            'cost_sheet_id' => $standard['cost_sheet_id'],
            'snapshot_hash' => null,
            'authority' => $complete
                ? 'BR-100 — authoritative standard snapshot vs complete defined actual basis'
                : 'PARTIAL — standard formula defined, but one or more actual/output authorities are incomplete',
            'status' => $complete ? 'DEFINED' : 'PARTIAL',
        ]);
    }
}
