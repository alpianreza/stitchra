<?php

namespace Modules\Production\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Production\Models\ProductionOrder;
use RuntimeException;

/** Read-only convergence evidence. Historical operational facts are never rewritten. */
class OperationalIntegrityService
{
    public function authorityMatrix(int $companyId, User $user): array
    {
        $this->assertAccess($user, $companyId);
        $this->assertActive($companyId);

        return [
            'rows' => [
                $this->row('Fabric consumption', 'Marker Log', 'Lay/Lay Roll', 'Both consume dispatch + physical Roll and update MO actual consumption differently', 'CONFLICT', 'New mixed execution BLOCKED; sole authority DECISION REQUIRED'),
                $this->row('Production output', 'qty_produced', 'No authoritative whole-MO source', 'Legacy value is consumed by Backflush, Packing, and Actual Cost fallback', 'NOT DEFINED', 'Compatibility preserved; writer remains absent'),
                $this->row('Inventory movement', 'Direct domain callers', 'ITS', 'All inspected stock operations converge on ITS; fabric dispatch consumption is an operational subledger control, not stock movement', 'CONVERGED', 'Preserve ITS source uniqueness and balance locks'),
                $this->row('Packing Input', 'Nullable historical QC source', 'QC FINAL PASS', 'Historical rows may lack source; new Packing requires BR-080', 'COMPATIBILITY', 'Preserve readability; no backfill'),
                $this->row('Shipment quantity', 'Legacy rows', 'Packing List / Carton Matrix', 'Delivery Schedule does not provide shipment allocation authority', 'CONVERGED', 'Preserve Iteration 14 boundary'),
                $this->row('GL posting', 'Existing AR/AP/Tax/Payment/Bank integrations', 'JournalService + OperationalPostingService', 'GR path uses one explicit deterministic posting key; production valuation events remain blocked', 'CONVERGED', 'No second accounting ledger/mechanism added'),
                $this->row('Actual Cost', 'Legacy qty_produced fallback', 'Computed read-only source evidence', 'Cost-per-unit and persisted costing authority remain undefined', 'PARTIAL', 'No costing or valuation ledger'),
            ],
            'states' => [
                'MARKER_LAY_CONSUMPTION_AUTHORITY' => 'DECISION REQUIRED',
                'MIXED_MARKER_LAY_NEW_EXECUTION' => 'BLOCKED',
                'HISTORICAL_CONSUMPTION_REWRITE' => 'PROHIBITED',
                'PRODUCTION_OUTPUT_AUTHORITY' => 'NOT DEFINED',
                'QTY_PRODUCED' => 'LEGACY COMPATIBILITY FALLBACK — NOT AUTHORITATIVE',
                'LEGACY_BACKFLUSH_CONVERGENCE' => 'BLOCKED — PRODUCTION_OUTPUT_AUTHORITY NOT DEFINED',
                'INVENTORY_AUTHORITY' => 'ITS',
                'ACCOUNTING_AUTHORITY' => 'EXISTING GL / JOURNALS',
                'ACCOUNTING_POSTING_CONFLICT' => 'NOT DETECTED FOR GR POSTING',
                'WIP_VALUATION' => 'NOT DEFINED',
                'FG_VALUATION' => 'NOT DEFINED',
                'SHIPMENT_VALUATION' => 'NOT DEFINED',
                'COGS' => 'NOT DEFINED',
            ],
            'safe_guards' => [
                'Marker writes reject an MO that already has Lay Roll consumption.',
                'Lay Roll writes and Lay completion reject an MO that already has Marker consumption.',
                'Historical mixed records remain readable; completion mutation is blocked instead of reconciled by assumption.',
                'ITS source key and GL posting_key retain existing deterministic idempotency.',
            ],
            'writes_performed' => false,
            'migration' => 'NONE',
        ];
    }

    public function inspect(ProductionOrder $productionOrder, User $user): array
    {
        return DB::transaction(function () use ($productionOrder, $user): array {
            $mo = ProductionOrder::withoutGlobalScopes()->whereKey($productionOrder->id)->firstOrFail();
            $this->assertAccess($user, (int) $mo->company_id);
            $this->assertActive((int) $mo->company_id);

            $markers = DB::table('marker_logs as marker')
                ->join('cut_orders as cut', 'cut.id', '=', 'marker.cut_order_id')
                ->where('cut.company_id', $mo->company_id)->where('cut.production_order_id', $mo->id)
                ->orderBy('marker.id')->get([
                    'marker.id', 'marker.cut_order_id', 'marker.roll_id', 'marker.qty_fabric_used_use',
                    'marker.qty_fabric_used_m', 'marker.created_at',
                ]);
            $layRolls = DB::table('lay_rolls as line')
                ->join('lays as lay', 'lay.id', '=', 'line.lay_id')
                ->join('cut_orders as cut', 'cut.id', '=', 'lay.cut_order_id')
                ->where('cut.company_id', $mo->company_id)->where('cut.production_order_id', $mo->id)
                ->orderBy('line.id')->get([
                    'line.id', 'line.lay_id', 'line.fabric_roll_id', 'line.qty_used', 'line.shade_override',
                    'lay.cut_order_id', 'lay.lay_no', 'lay.status as lay_status', 'line.created_at',
                ]);
            $hasMarkers = $markers->isNotEmpty();
            $hasLayRolls = $layRolls->isNotEmpty();
            $mixed = $hasMarkers && $hasLayRolls;

            $dispatch = DB::table('fabric_dispatch_balances')->where('company_id', $mo->company_id)
                ->where('production_order_id', $mo->id)->orderBy('id')->get([
                    'id', 'roll_id', 'warehouse_id', 'uom_id', 'qty_dispatched', 'qty_consumed', 'qty_returned',
                ]);
            $issues = DB::table('material_issues')->where('company_id', $mo->company_id)
                ->where('production_order_id', $mo->id)->orderBy('id')->get(['id', 'doc_no', 'mode', 'status', 'warehouse_id']);
            $issueIds = $issues->pluck('id');
            $issueLines = $issueIds->isEmpty() ? collect() : DB::table('material_issue_lines')
                ->whereIn('material_issue_id', $issueIds)->orderBy('id')->get(['id', 'material_issue_id', 'material_id', 'roll_id', 'qty', 'uom_id']);
            $issueModesByMaterial = $issueLines->map(function ($line) use ($issues): array {
                $issue = $issues->firstWhere('id', $line->material_issue_id);
                return ['material_id' => (int) $line->material_id, 'mode' => $issue?->mode, 'qty' => (float) $line->qty];
            })->groupBy('material_id')->map(fn ($rows) => [
                'modes' => $rows->pluck('mode')->filter()->unique()->values(),
                'qty' => round((float) $rows->sum('qty'), 4),
                'actual_backflush_overlap' => $rows->pluck('mode')->contains('ACTUAL') && $rows->pluck('mode')->contains('BACKFLUSH'),
            ]);

            $packing = DB::table('packing_lists')->where('company_id', $mo->company_id)
                ->where('production_order_id', $mo->id)->orderBy('id')->get(['id', 'doc_no', 'qc_inspection_id', 'status']);
            $packingIds = $packing->pluck('id');
            $shipments = $packingIds->isEmpty() ? collect() : DB::table('shipments')
                ->where('company_id', $mo->company_id)->whereIn('packing_list_id', $packingIds)
                ->orderBy('id')->get(['id', 'doc_no', 'packing_list_id', 'status']);

            $sourceMovements = $this->sourceMovementEvidence((int) $mo->company_id, $issueIds, $packingIds, $shipments->pluck('id'));
            $duplicateMovements = $sourceMovements->groupBy(fn ($row) => implode('|', [
                $row->movement_type, $row->source_document_type, $row->source_document_id,
            ]))->filter(fn ($rows) => $rows->count() > 1)->map(fn ($rows) => $rows->pluck('id')->values())->values();

            $cutOutputs = DB::table('cut_outputs as output')->join('lays as lay', 'lay.id', '=', 'output.lay_id')
                ->join('cut_orders as cut', 'cut.id', '=', 'lay.cut_order_id')->where('cut.production_order_id', $mo->id)->count();
            $bundles = DB::table('bundles')->where('company_id', $mo->company_id)->where('production_order_id', $mo->id)->count();
            $scans = DB::table('production_scans')->where('company_id', $mo->company_id)->where('production_order_id', $mo->id)->count();
            $wip = DB::table('wip_transfers')->where('company_id', $mo->company_id)->where('production_order_id', $mo->id)->count();
            $qcPass = DB::table('qc_inspections')->where('company_id', $mo->company_id)->where('production_order_id', $mo->id)
                ->where('stage', 'FINAL')->where('verdict', 'PASS')->exists();

            $path = $mixed ? '[CONFLICT: Marker + Lay Roll]' : ($hasLayRolls ? 'Lay → Lay Roll' : ($hasMarkers ? 'Legacy Marker' : '[not executed]'));

            return [
                'production_order' => ['id' => $mo->id, 'doc_no' => $mo->doc_no, 'status' => $mo->status, 'company_id' => (int) $mo->company_id],
                'authority_conflict' => [
                    'domain' => 'FABRIC_CONSUMPTION',
                    'marker_present' => $hasMarkers,
                    'lay_roll_present' => $hasLayRolls,
                    'mixed_path' => $mixed,
                    'status' => $mixed ? 'CONFLICT' : ($hasMarkers ? 'LEGACY' : ($hasLayRolls ? 'PARTIAL' : 'NOT DEFINED')),
                    'new_mixed_execution' => 'BLOCKED',
                    'historical_mutation' => false,
                    'authority_resolution' => 'DECISION REQUIRED',
                    'reason' => 'Marker and Lay Roll both consume dispatch/physical Roll; Marker completion increments while Lay completion overwrites MO actual consumption.',
                ],
                'consumption_evidence' => [
                    'markers' => $markers->map(fn ($row) => (array) $row)->values(),
                    'lay_rolls' => $layRolls->map(fn ($row) => (array) $row)->values(),
                    'dispatch_balances' => $dispatch->map(fn ($row) => (array) $row)->values(),
                ],
                'qty_produced' => [
                    'stored_value' => (float) $mo->qty_produced,
                    'classification' => 'LEGACY COMPATIBILITY FALLBACK — NOT AUTHORITATIVE',
                    'writer' => 'NOT FOUND',
                    'production_output_authority' => 'NOT DEFINED',
                    'consumers' => [
                        ['consumer' => 'Backflush', 'classification' => 'LEGACY', 'state' => 'BLOCKED — PRODUCTION_OUTPUT_AUTHORITY NOT DEFINED'],
                        ['consumer' => 'Packing ceiling/PACKED transition', 'classification' => 'COMPATIBILITY', 'state' => 'PRESERVED'],
                        ['consumer' => 'Actual Cost fallback', 'classification' => 'LEGACY', 'state' => 'COMPUTED_READ_ONLY'],
                        ['consumer' => 'Production UI progress', 'classification' => 'LEGACY', 'state' => 'REMOVED AS AUTHORITY'],
                    ],
                ],
                'backflush' => [
                    'endpoint' => 'POST production/orders/{productionOrder}/issues/backflush',
                    'status' => 'LEGACY', 'uses_qty_produced' => true,
                    'writes_inventory_through' => 'MaterialIssue → ITS MATERIAL_ISSUE',
                    'bypasses_its' => false, 'bypasses_reservation' => false,
                    'actual_backflush_overlap' => $issueModesByMaterial->contains(fn ($row) => $row['actual_backflush_overlap']),
                    'material_evidence' => $issueModesByMaterial,
                    'convergence' => 'BLOCKED — authoritative production output and ACTUAL-vs-BACKFLUSH precedence are not locked',
                ],
                'inventory' => [
                    'authority' => 'ITS',
                    'source_movements' => $sourceMovements->map(fn ($row) => (array) $row)->values(),
                    'duplicate_source_movements' => $duplicateMovements,
                    'duplicate_detected' => $duplicateMovements->isNotEmpty(),
                    'fabric_dispatch_note' => 'Marker/Lay dispatch consumption is not a second stock ledger; conflict is actual-consumption authority.',
                ],
                'operational_chain' => [
                    'cutting_path' => $path, 'cut_outputs' => $cutOutputs, 'bundles' => $bundles,
                    'production_scans' => $scans, 'wip_transfers' => $wip,
                    'qc_final_pass' => $qcPass, 'packing_lists' => $packing->count(), 'shipments' => $shipments->count(),
                    'sewing_wip' => 'Bundle-only full-quantity scans; append-only WIP transfers; no ITS movement created',
                    'finishing' => 'Requires Sewing→Finishing WIP source and forward-only Bundle scans',
                    'packing' => 'New path requires QC FINAL PASS; legacy missing source remains readable without backfill',
                    'fg_shipment' => 'Packing/Carton → ITS PRODUCTION_RECEIPT → FG → Shipment → ITS SHIPMENT',
                ],
                'accounting' => [
                    'authority' => 'EXISTING GL / JOURNALS',
                    'gr_posting' => 'Explicit GR POSTED + one ITS PURCHASE_RECEIPT → deterministic GR_RECEIPT posting_key',
                    'accounting_posting_conflict' => 'NOT DETECTED FOR GR POSTING',
                    'production_events' => 'BLOCKED where valuation/COGS authority is NOT DEFINED',
                ],
                'legacy_endpoints' => $this->legacyEndpoints(),
                'lineage' => [
                    'forward' => "MO → Cutting → {$path} → Cut Output/legacy Bundle → Sewing → WIP → Finishing → QC → Packing → FG → Shipment → ITS",
                    'reverse' => "ITS SHIPMENT → Shipment → FG/PRODUCTION_RECEIPT → Packing → QC → Finishing → WIP/Sewing → Bundle → Cut Output/legacy Bundle → {$path} → Cutting → MO",
                    'history_policy' => 'Show actual persisted Marker or Lay path; never backfill or rewrite history.',
                ],
                'resolved_conflicts' => [
                    'New Marker-after-Lay and Lay-after-Marker consumption attempts are blocked at service boundary.',
                    'ITS prevents duplicate movement per company + type + source document.',
                    'GR journal posting uses deterministic posting_key and returns existing journal on replay.',
                    'Production scans reject duplicate stage/operation/direction and offline event payload conflicts.',
                ],
                'decision_required' => [
                    'Choose sole actual fabric consumption authority and historical mixed-path reconciliation policy.',
                    'Define production output authority before replacing qty_produced or activating authoritative backflush.',
                    'Define ACTUAL Material Issue versus BACKFLUSH precedence/delta semantics.',
                    'Define WIP/FG/Shipment valuation and COGS before production accounting posting.',
                ],
                'writes_performed' => false, 'migration' => 'NONE',
            ];
        });
    }

    private function sourceMovementEvidence(int $companyId, $issueIds, $packingIds, $shipmentIds)
    {
        return DB::table('stock_movements')->where('company_id', $companyId)
            ->where(function ($query) use ($issueIds, $packingIds, $shipmentIds): void {
                if ($issueIds->isNotEmpty()) $query->orWhere(fn ($q) => $q->where('source_document_type', 'material_issues')->whereIn('source_document_id', $issueIds));
                if ($packingIds->isNotEmpty()) $query->orWhere(fn ($q) => $q->where('source_document_type', 'packing_lists')->whereIn('source_document_id', $packingIds));
                if ($shipmentIds->isNotEmpty()) $query->orWhere(fn ($q) => $q->where('source_document_type', 'shipments')->whereIn('source_document_id', $shipmentIds));
            })->orderBy('id')->get(['id', 'doc_no', 'movement_type', 'source_document_type', 'source_document_id']);
    }

    private function legacyEndpoints(): array
    {
        return [
            ['endpoint' => 'POST cutting/orders/{cutOrder}/markers', 'current_use' => 'Consumes dispatch/Roll and records efficiency', 'authority' => 'LEGACY', 'conflict' => 'Lay Roll consumption', 'action' => 'PRESERVE + BLOCK MIXED CONSUMPTION'],
            ['endpoint' => 'POST cutting/lays/{lay}/rolls', 'current_use' => 'Lay Roll/shade execution', 'authority' => 'PARTIAL', 'conflict' => 'Marker consumption authority open', 'action' => 'PRESERVE + BLOCK MIXED CONSUMPTION'],
            ['endpoint' => 'POST production/orders/{productionOrder}/issues/backflush', 'current_use' => 'Creates BACKFLUSH Material Issue through ITS', 'authority' => 'LEGACY', 'conflict' => 'qty_produced and ACTUAL precedence undefined', 'action' => 'COMPATIBILITY + DECISION REQUIRED'],
            ['endpoint' => 'POST shopfloor/scans', 'current_use' => 'Bundle-based Sewing/Finishing event', 'authority' => 'DEFINED', 'conflict' => 'No bypass found in inspected service', 'action' => 'PRESERVE'],
            ['endpoint' => 'POST packing/lists/from-so/{salesOrder}', 'current_use' => 'New rows require MO/QC FINAL source', 'authority' => 'DEFINED', 'conflict' => 'Historical source may be NULL', 'action' => 'COMPATIBILITY; NO BACKFILL'],
            ['endpoint' => 'POST shipping/shipments/from-pl/{packingList}', 'current_use' => 'Carton-derived Shipment', 'authority' => 'DEFINED', 'conflict' => 'Delivery Schedule link undefined', 'action' => 'PRESERVE'],
            ['endpoint' => 'POST finance/gl/operational-postings/goods-receipts/{goodsReceipt}', 'current_use' => 'Explicit idempotent GR posting', 'authority' => 'DEFINED', 'conflict' => 'No duplicate GR mechanism detected', 'action' => 'PRESERVE'],
        ];
    }

    private function row(string $domain, string $legacy, string $current, string $conflict, string $status, string $resolution): array
    {
        return compact('domain', 'legacy', 'current', 'conflict', 'status', 'resolution');
    }

    private function assertActive(int $companyId): void
    {
        if (! DB::table('companies')->where('id', $companyId)->where('is_active', true)->whereNull('deleted_at')->exists()) {
            throw new RuntimeException('Company operational integrity tidak aktif.');
        }
    }

    private function assertAccess(User $user, int $companyId): void
    {
        if ((int) $user->company_id !== $companyId && ! $user->companies()->whereKey($companyId)->exists()) {
            throw new RuntimeException('User tidak memiliki akses ke company operational integrity.');
        }
    }
}
