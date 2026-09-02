<?php

namespace Modules\Production\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Production\Models\ProductionOrder;
use RuntimeException;

/** Read-only evidence view. It never writes qty_produced or creates an output ledger. */
class ProductionOutputAuthorityService
{
    public function inspect(ProductionOrder $productionOrder, User $user): array
    {
        return DB::transaction(function () use ($productionOrder, $user): array {
            $mo = ProductionOrder::withoutGlobalScopes()->whereKey($productionOrder->id)->firstOrFail();
            $this->assertAccess($user, (int) $mo->company_id);
            $company = DB::table('companies')->where('id', $mo->company_id)->whereNull('deleted_at')->first();
            if ($company === null || ! (bool) $company->is_active) throw new RuntimeException('Company Production tidak aktif.');

            $cutOutputs = DB::table('cut_outputs as output')
                ->join('lays as lay', 'lay.id', '=', 'output.lay_id')
                ->join('cut_orders as cut', 'cut.id', '=', 'lay.cut_order_id')
                ->where('cut.company_id', $mo->company_id)->where('cut.production_order_id', $mo->id)
                ->orderBy('output.id')->get(['output.id', 'output.lay_id', 'output.cut_order_line_id', 'output.qty_cut', 'lay.status as lay_status', 'cut.status as cut_order_status']);
            $bundles = DB::table('bundles')->where('company_id', $mo->company_id)
                ->where('production_order_id', $mo->id)->orderBy('id')
                ->get(['id', 'bundle_no', 'cut_output_id', 'qty', 'current_stage', 'status']);
            $finalOperation = DB::table('routing_operations')->where('routing_version_id', $mo->routing_version_id)
                ->orderByDesc('seq')->first(['operation_id', 'seq']);
            $sewing = $finalOperation ? DB::table('production_scans')->where('company_id', $mo->company_id)
                ->where('production_order_id', $mo->id)->where('stage', 'SEWING')->where('direction', 'OUT')
                ->where('operation_id', $finalOperation->operation_id)->orderBy('id')
                ->get(['id', 'bundle_id', 'operation_id', 'qty', 'scanned_at']) : collect();
            $finishing = DB::table('production_scans')->where('company_id', $mo->company_id)
                ->where('production_order_id', $mo->id)->where('stage', 'FINISHING')->where('direction', 'OUT')
                ->orderBy('id')->get(['id', 'bundle_id', 'operation_id', 'qty', 'scanned_at']);
            $wip = DB::table('wip_transfers')->where('company_id', $mo->company_id)
                ->where('production_order_id', $mo->id)->orderBy('id')
                ->get(['id', 'bundle_id', 'source_scan_id', 'from_stage', 'to_stage', 'qty', 'transferred_at']);
            $qc = DB::table('qc_inspections')->where('company_id', $mo->company_id)
                ->where('production_order_id', $mo->id)->where('stage', 'FINAL')
                ->orderByDesc('cycle')->orderByDesc('id')->get(['id', 'doc_no', 'cycle', 'lot_qty', 'verdict', 'updated_at']);
            $packing = DB::table('packing_lists as packing')->leftJoin('cartons', 'cartons.packing_list_id', '=', 'packing.id')
                ->leftJoin('carton_lines', 'carton_lines.carton_id', '=', 'cartons.id')
                ->where('packing.company_id', $mo->company_id)->where('packing.production_order_id', $mo->id)
                ->groupBy('packing.id', 'packing.doc_no', 'packing.qc_inspection_id', 'packing.status')
                ->orderBy('packing.id')->get([
                    'packing.id', 'packing.doc_no', 'packing.qc_inspection_id', 'packing.status',
                    DB::raw('COALESCE(SUM(carton_lines.qty),0) as packed_qty'),
                ]);
            $packingIds = $packing->pluck('id');
            $receipts = $packingIds->isEmpty() ? collect() : DB::table('stock_ledger')
                ->where('company_id', $mo->company_id)->where('movement_type', 'PRODUCTION_RECEIPT')
                ->where('source_document_type', 'packing_lists')->whereIn('source_document_id', $packingIds)
                ->orderBy('id')->get(['id', 'source_document_id', 'style_id', 'colorway_id', 'size_id', 'warehouse_id', 'qty_in', 'unit_cost', 'total_cost', 'created_at']);
            $latestQc = $qc->first();

            $matrix = [
                $this->candidate('Cut Output', (float) $cutOutputs->sum('qty_cut'), 'Lay/Cut Order; exact Bundle total on completed Lay', 'PF-05 cut quantity authority only', 'Creates Bundles', 'DEFINED'),
                $this->candidate('Bundle', (float) $bundles->sum('qty'), 'ACTIVE/REWORK and current_stage', 'Derived from Cut Output for new flow; legacy Bundles may lack source', 'Sewing scans and WIP transfer', 'DERIVED'),
                $this->candidate('Sewing final OUT', (float) $sewing->sum('qty'), 'Append-only full-Bundle OUT; duplicate blocked', 'BR-007/PF-06 sewing output and BR-064 transfer authority only', 'SEWING→FINISHING WIP', 'DEFINED'),
                $this->candidate('Finishing OUT', (float) $finishing->sum('qty'), 'Append-only full-Bundle OUT', 'No mandatory terminal Finishing operation/completion marker', 'Evidence before QC; no direct Carton allocation', 'PARTIAL'),
                $this->candidate('QC FINAL PASS', $latestQc?->verdict === 'PASS' ? (float) $latestQc->lot_qty : 0.0, 'Inspection cycle verdict', 'BR-080 Packing eligibility only; not MO production output', 'Packing Input', $latestQc?->verdict === 'PASS' ? 'DEFINED' : 'NOT DEFINED'),
                $this->candidate('Packing', (float) $packing->sum('packed_qty'), 'DRAFT/APPROVED/SHIPPED/CANCELLED', 'Downstream quantity constrained by QC FINAL PASS; direct Bundle allocation undefined', 'PRODUCTION_RECEIPT source', 'DERIVED'),
                $this->candidate('PRODUCTION_RECEIPT', (float) $receipts->sum('qty_in'), 'Append-only ITS receipt per Packing List', 'PF-09/BR-013 FG quantity authority only; FG valuation undefined', 'Operational FG stock', 'DEFINED'),
            ];

            return [
                'production_order' => [
                    'id' => $mo->id, 'doc_no' => $mo->doc_no, 'company_id' => (int) $mo->company_id,
                    'status' => $mo->status, 'qty_planned' => (float) $mo->qty_planned,
                    'actual_start' => $mo->actual_start?->toDateString(), 'actual_end' => $mo->actual_end?->toDateString(),
                ],
                'production_output_authority' => [
                    'status' => 'NOT DEFINED', 'authoritative_source' => null, 'authoritative_qty' => null,
                    'reason' => 'Stage-level authorities exist, but no locked rule selects one source as authoritative MO production output.',
                ],
                'qty_produced' => [
                    'stored_value' => (float) $mo->qty_produced, 'status' => 'LEGACY', 'authoritative' => false,
                    'operational_writer' => 'NOT FOUND IN INSPECTED PRODUCTION/CUTTING/SHOPFLOOR/QC/PACKING SERVICES',
                    'schema' => 'DEFAULT 0; model remains fillable for compatibility',
                    'write_endpoint' => null,
                    'warning' => 'LEGACY COMPATIBILITY FALLBACK — NOT AUTHORITATIVE',
                ],
                'production_completion' => [
                    'status' => 'NOT DEFINED', 'completion_endpoint' => null, 'completion_event' => null,
                    'explicit_completed_status' => false,
                    'current_status_progression' => 'CUTTING/SEWING/FINISHING/QC are stage transitions; PACKED currently depends on legacy qty_produced; CLOSED completion authority was not established.',
                    'reason' => 'PRODUCTION_COMPLETION_LIFECYCLE = NOT DEFINED',
                ],
                'candidate_matrix' => $matrix,
                'quantity_evidence' => [
                    'cut_outputs' => $cutOutputs->map(fn ($row) => (array) $row)->values(),
                    'bundles' => $bundles->map(fn ($row) => (array) $row)->values(),
                    'sewing_final_out' => $sewing->map(fn ($row) => (array) $row)->values(),
                    'wip_transfers' => $wip->map(fn ($row) => (array) $row)->values(),
                    'finishing_out' => $finishing->map(fn ($row) => (array) $row)->values(),
                    'qc_final' => $qc->map(fn ($row) => (array) $row)->values(),
                    'packing' => $packing->map(fn ($row) => (array) $row)->values(),
                    'production_receipts' => $receipts->map(fn ($row) => (array) $row)->values(),
                ],
                'partial_production' => [
                    'status' => 'NOT DEFINED',
                    'existing_behavior' => 'Cut Output, QC lot, Packing Lists, and FG receipts may be partial; scan transactions require full Bundle quantity.',
                    'reason' => 'PARTIAL_PRODUCTION_RULE = NOT DEFINED as one cross-stage reconciliation rule.',
                ],
                'defect_rework_scrap' => [
                    'status' => 'NOT DEFINED',
                    'reason' => 'DEFECT_OUTPUT_ARITHMETIC = NOT DEFINED; NCR/Rework evidence is not subtracted from a manufactured output total.',
                ],
                'boundaries' => [
                    'qc' => 'BR-080 DEFINED FOR PACKING ELIGIBILITY; QC_PRODUCTION_OUTPUT_AUTHORITY = NOT DEFINED',
                    'packing' => 'DEFINED DOWNSTREAM CONTROL; NOT MO OUTPUT AUTHORITY',
                    'fg' => 'PRODUCTION_RECEIPT IS FG QUANTITY AUTHORITY; FG_VALUATION = NOT DEFINED',
                    'actual_cost' => 'COMPUTED READ-ONLY; COST_PER_UNIT = NOT DEFINED',
                    'wip_valuation' => 'NOT DEFINED', 'cogs' => 'NOT DEFINED',
                ],
                'downstream_consumers' => [
                    ['consumer' => 'Actual Cost labor/OH', 'classification' => 'PARTIAL', 'use' => 'final-operation scan evidence; legacy fallback remains explicitly non-authoritative'],
                    ['consumer' => 'Backflush', 'classification' => 'LEGACY', 'use' => 'reads qty_produced; preserved and flagged, not expanded'],
                    ['consumer' => 'Packing finalize/status', 'classification' => 'LEGACY', 'use' => 'QC controls are defined; extra qty_produced ceiling/PACKED transition remains compatibility behavior'],
                    ['consumer' => 'Production order list/progress', 'classification' => 'LEGACY', 'use' => 'must not label qty_produced as authoritative progress'],
                    ['consumer' => 'Dashboard/reporting', 'classification' => 'DERIVED', 'use' => 'uses final Sewing OUT scans and Bundle WIP, not qty_produced'],
                    ['consumer' => 'FG/Shipment', 'classification' => 'DERIVED', 'use' => 'uses Packing/ITS quantity; not MO output authority or cost denominator'],
                    ['consumer' => 'Variance/completion', 'classification' => 'BLOCKED', 'use' => 'complete output/completion authority is absent'],
                ],
                'lineage' => [
                    'forward' => 'MO → Cut Output → Bundle → Sewing OUT → WIP Transfer → Finishing OUT evidence → QC FINAL → Packing → ITS PRODUCTION_RECEIPT → FG',
                    'reverse' => 'FG → PRODUCTION_RECEIPT → Packing → QC FINAL → Finishing/Sewing/Bundle/Cut Output evidence → MO',
                    'authority_boundary' => 'Production Output Authority = NOT DEFINED; no single source is fabricated.',
                ],
                'writes_performed' => false, 'migration' => 'NONE',
            ];
        });
    }

    private function candidate(string $source, float $quantity, string $lifecycle, string $authority, string $usage, string $status): array
    {
        return ['candidate_source' => $source, 'quantity' => round($quantity, 4), 'lifecycle' => $lifecycle,
            'existing_authority' => $authority, 'downstream_usage' => $usage, 'status' => $status];
    }

    private function assertAccess(User $user, int $companyId): void
    {
        if ((int) $user->company_id !== $companyId && ! $user->companies()->whereKey($companyId)->exists()) {
            throw new RuntimeException('User tidak memiliki akses ke company Production.');
        }
    }
}
