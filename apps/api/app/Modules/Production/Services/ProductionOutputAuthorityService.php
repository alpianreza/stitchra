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
        return DB::transaction(function () use ($productionOrder, $user): array {
            $mo = ProductionOrder::withoutGlobalScopes()->whereKey($productionOrder->id)->firstOrFail();
            $this->assertAccess($user, (int) $mo->company_id);
            $company = DB::table('companies')->where('id', $mo->company_id)->whereNull('deleted_at')->first();
            if ($company === null || ! (bool) $company->is_active) throw new RuntimeException('Company Production tidak aktif.');

            $named = $this->measures->all($mo);
            $cutOutputs = DB::table('cut_outputs as output')->join('lays as lay', 'lay.id', '=', 'output.lay_id')
                ->join('cut_orders as cut', 'cut.id', '=', 'lay.cut_order_id')->where('cut.company_id', $mo->company_id)
                ->where('cut.production_order_id', $mo->id)->orderBy('output.id')
                ->get(['output.id', 'output.lay_id', 'output.cut_order_line_id', 'output.qty_cut', 'lay.status as lay_status', 'cut.status as cut_order_status']);
            $bundles = DB::table('bundles')->where('company_id', $mo->company_id)->where('production_order_id', $mo->id)
                ->orderBy('id')->get(['id', 'bundle_no', 'cut_output_id', 'qty', 'current_stage', 'status']);
            $scans = DB::table('production_scans')->where('company_id', $mo->company_id)->where('production_order_id', $mo->id)
                ->orderBy('id')->get(['id', 'bundle_id', 'operation_id', 'stage', 'direction', 'qty', 'scanned_at']);
            $wip = DB::table('wip_transfers')->where('company_id', $mo->company_id)->where('production_order_id', $mo->id)
                ->orderBy('id')->get(['id', 'bundle_id', 'source_scan_id', 'from_stage', 'to_stage', 'qty', 'transferred_at']);
            $qc = DB::table('qc_inspections')->where('company_id', $mo->company_id)->where('production_order_id', $mo->id)
                ->where('stage', 'FINAL')->orderByDesc('cycle')->orderByDesc('id')->get(['id', 'doc_no', 'cycle', 'lot_qty', 'verdict', 'updated_at']);
            $packing = DB::table('packing_lists as packing')->leftJoin('cartons', 'cartons.packing_list_id', '=', 'packing.id')
                ->leftJoin('carton_lines', 'carton_lines.carton_id', '=', 'cartons.id')->where('packing.company_id', $mo->company_id)
                ->where('packing.production_order_id', $mo->id)->groupBy('packing.id', 'packing.doc_no', 'packing.qc_inspection_id', 'packing.status')
                ->orderBy('packing.id')->get(['packing.id', 'packing.doc_no', 'packing.qc_inspection_id', 'packing.status', DB::raw('COALESCE(SUM(carton_lines.qty),0) as packed_qty')]);
            $packingIds = $packing->pluck('id');
            $receipts = $packingIds->isEmpty() ? collect() : DB::table('stock_ledger')->where('company_id', $mo->company_id)
                ->where('movement_type', 'PRODUCTION_RECEIPT')->where('source_document_type', 'packing_lists')->whereIn('source_document_id', $packingIds)
                ->orderBy('id')->get(['id', 'source_document_id', 'style_id', 'colorway_id', 'size_id', 'warehouse_id', 'qty_in', 'unit_cost', 'total_cost', 'created_at']);

            $matrix = [
                $this->candidate($named['CUT_OUTPUT'], 'Completed Lay/Cut Order', 'Cut quantity only', 'Creates Bundle'),
                ['measure_key' => 'BUNDLE_QTY', 'candidate_source' => 'Bundle Qty', 'quantity' => round((float) $bundles->sum('qty'), 4),
                    'lifecycle' => 'ACTIVE/REWORK and current_stage', 'existing_authority' => 'Derived from Cut Output for new flow',
                    'downstream_usage' => 'Sewing scans and WIP transfer', 'status' => 'DERIVED'],
                $this->candidate($named['SEWING_FINAL_OUT'], 'Final routing operation OUT', 'Sewing stage output', 'Labor/OH and WIP evidence'),
                $this->candidate($named['FINISHING_OUT'], 'Finishing OUT scan', 'Finishing stage output only', 'QC evidence'),
                $this->candidate($named['QC_FINAL_PASS'], 'Latest FINAL PASS cycle', 'Packing eligibility only', 'Packing Input'),
                $this->candidate($named['PACKED_QTY'], 'APPROVED/SHIPPED Packing', 'Packed quantity only', 'FG receipt source'),
                $this->candidate($named['FG_RECEIVED_QTY'], 'Append-only ITS receipt', 'FG quantity authority only', 'FG stock'),
            ];

            return [
                'production_order' => ['id' => $mo->id, 'doc_no' => $mo->doc_no, 'company_id' => (int) $mo->company_id,
                    'status' => $mo->status, 'qty_planned' => (float) $mo->qty_planned, 'actual_start' => $mo->actual_start?->toDateString(), 'actual_end' => $mo->actual_end?->toDateString()],
                'production_output_authority' => ['status' => 'SEPARATE_NAMED_MEASURES', 'business_rule' => 'BR-065',
                    'authoritative_source' => null, 'authoritative_qty' => null,
                    'reason' => 'BR-065 prohibits one generic whole-MO output. Every consumer must name its stage measure.'],
                'named_measures' => $named,
                'qty_produced' => ['stored_value' => (float) $mo->qty_produced, 'status' => 'LEGACY', 'authoritative' => false,
                    'operational_writer' => 'NOT FOUND', 'write_endpoint' => null, 'warning' => 'LEGACY COMPATIBILITY — NOT AUTHORITY AND NOT A FALLBACK'],
                'production_completion' => ['status' => 'NO_GENERIC_COMPLETION', 'completion_endpoint' => null, 'completion_event' => null,
                    'explicit_completed_status' => false, 'current_status_progression' => 'Stage transitions remain separate from named quantities.',
                    'reason' => 'BR-065 does not create a universal production-completion quantity.'],
                'candidate_matrix' => $matrix,
                'quantity_evidence' => ['cut_outputs' => $cutOutputs->map(fn ($row) => (array) $row)->values(),
                    'bundles' => $bundles->map(fn ($row) => (array) $row)->values(), 'production_scans' => $scans->map(fn ($row) => (array) $row)->values(),
                    'wip_transfers' => $wip->map(fn ($row) => (array) $row)->values(), 'qc_final' => $qc->map(fn ($row) => (array) $row)->values(),
                    'packing' => $packing->map(fn ($row) => (array) $row)->values(), 'production_receipts' => $receipts->map(fn ($row) => (array) $row)->values()],
                'partial_production' => ['status' => 'SUPPORTED_AS_SEPARATE_MEASURES', 'existing_behavior' => 'Each stage measure may progress independently.',
                    'reason' => 'No cross-stage arithmetic or generic output is inferred.'],
                'defect_rework_scrap' => ['status' => 'NOT DEFINED', 'reason' => 'BR-065 names measures but does not invent defect/rework/scrap arithmetic.'],
                'boundaries' => ['qc' => 'QC_FINAL_PASS is Packing eligibility, not universal output', 'packing' => 'PACKED_QTY is downstream measure',
                    'fg' => 'FG_RECEIVED_QTY is FG quantity authority', 'actual_cost' => 'SEWING_FINAL_OUT is the explicit labor/OH measure',
                    'wip_valuation' => 'NOT IMPLEMENTED IN THIS BATCH', 'cogs' => 'NOT IMPLEMENTED IN THIS BATCH'],
                'downstream_consumers' => [
                    ['consumer' => 'Actual Cost labor/OH', 'classification' => 'DEFINED', 'use' => 'SEWING_FINAL_OUT'],
                    ['consumer' => 'Backflush', 'classification' => 'DEFINED', 'use' => 'Configured one Named Stage per MO material'],
                    ['consumer' => 'Packing eligibility/status', 'classification' => 'DEFINED', 'use' => 'QC_FINAL_PASS and PACKED_QTY'],
                    ['consumer' => 'FG quantity', 'classification' => 'DEFINED', 'use' => 'FG_RECEIVED_QTY'],
                ],
                'lineage' => ['forward' => 'MO → Cut Output → Bundle → Sewing Final OUT → Finishing OUT → QC FINAL PASS → Packed Qty → FG Received Qty',
                    'reverse' => 'FG Received Qty → Packing → QC → Finishing/Sewing → Bundle → Cut Output → MO',
                    'authority_boundary' => 'BR-065: each named measure retains its stage scope; no universal qty is fabricated.'],
                'writes_performed' => false, 'migration' => '2026_09_03_000028_lock_material_consumption_authority',
            ];
        });
    }

    private function candidate(array $measure, string $lifecycle, string $authority, string $usage): array
    {
        return ['measure_key' => $measure['key'], 'candidate_source' => $measure['label'], 'quantity' => $measure['qty'] ?? 0.0,
            'lifecycle' => $lifecycle, 'existing_authority' => $authority, 'downstream_usage' => $usage, 'status' => $measure['status']];
    }

    private function assertAccess(User $user, int $companyId): void
    {
        if ((int) $user->company_id !== $companyId && ! $user->companies()->whereKey($companyId)->exists()) throw new RuntimeException('User tidak memiliki akses ke company Production.');
    }
}
