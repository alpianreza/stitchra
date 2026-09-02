<?php

namespace Modules\Finance\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Finance\Models\AccountMapping;
use Modules\Finance\Models\Journal;
use Modules\Production\Models\ProductionOrder;
use Modules\Shipping\Models\Shipment;
use RuntimeException;

/**
 * Read-only WIP/FG/COGS authority boundary.
 * ITS stays quantity authority; this service creates no stock, valuation, cost,
 * or journal record and never promotes source cost evidence into FG/COGS.
 */
class ValuationBoundaryService
{
    public function authorityMatrix(int $companyId, User $user): array
    {
        $company = $this->activeCompany($companyId);
        $this->assertAccess($user, $companyId);
        $mapped = AccountMapping::withoutGlobalScopes()->where('company_id', $companyId)->pluck('event')->flip();
        $row = fn (string $boundary, string $quantity, string $cost, ?string $event, string $status, string $reason): array => [
            'boundary' => $boundary,
            'quantity_authority' => $quantity,
            'cost_authority' => $cost,
            'accounting_event' => $event,
            'mapping_configured' => $event !== null && $mapped->has($event),
            'status' => $status,
            'posting_allowed' => false,
            'reason' => $reason,
        ];

        return [
            'company' => ['id' => (int) $company->id, 'code' => $company->code, 'base_currency' => $company->base_currency],
            'rows' => [
                $row('Material Issue → WIP', 'ITS MATERIAL_ISSUE removes RM; BR-064 tracks Bundle WIP transfers', 'Valued material rows are Actual Cost evidence only; WIP valuation is NOT DEFINED', 'MATERIAL_ISSUE', 'BLOCKED', 'MATERIAL_ISSUE_WIP = NOT DEFINED'),
                $row('Material Return', 'ITS PRODUCTION_RETURN', 'Unambiguous issue cost may be stored; mixed-cost allocation and WIP reversal are NOT DEFINED', null, 'NOT DEFINED', 'PRODUCTION_RETURN_ACCOUNTING = NOT DEFINED'),
                $row('Production Output → FG', 'Packing List/Carton → ITS PRODUCTION_RECEIPT', 'No authoritative FG cost basis or denominator', 'PRODUCTION_RECEIPT', 'BLOCKED', 'FG_VALUATION = NOT DEFINED'),
                $row('FG → Shipment', 'ITS SHIPMENT from traceable Packing List receipt', 'FG issue valuation source is NOT DEFINED', null, 'NOT DEFINED', 'SHIPMENT_VALUATION = NOT DEFINED'),
                $row('Shipment → COGS', 'ITS SHIPMENT quantity; BR-083 identifies the boundary', 'COGS amount/basis is NOT DEFINED', 'SHIPMENT_COGS', 'BLOCKED', 'COGS = NOT DEFINED'),
            ],
            'actual_cost_dependency' => 'FG/COGS valuation depends on official costing authority; Iteration 10 remains computed read-only.',
            'cost_per_unit' => 'NOT DEFINED — no denominator selected.',
            'wip_valuation' => 'NOT DEFINED — operational lineage only.',
            'fg_valuation' => 'NOT DEFINED — no valuation ledger, cost document, or backfill.',
            'shipment_valuation' => 'NOT DEFINED — operational shipment remains available.',
            'cogs' => 'NOT DEFINED — no SHIPMENT_COGS journal created.',
            'operational_reversal_accounting' => 'NOT DEFINED',
            'foreign_currency_inventory_valuation' => 'NOT DEFINED',
            'late_transaction_treatment' => 'NOT DEFINED — CLOSED periods are never silently bypassed.',
        ];
    }

    public function productionOrderBoundary(ProductionOrder $productionOrder, User $user): array
    {
        return DB::transaction(function () use ($productionOrder, $user): array {
            $mo = ProductionOrder::withoutGlobalScopes()->whereKey($productionOrder->id)->lockForUpdate()->firstOrFail();
            $company = $this->activeCompany((int) $mo->company_id);
            $this->assertAccess($user, (int) $mo->company_id);
            $issueIds = DB::table('material_issues')->where('company_id', $mo->company_id)->where('production_order_id', $mo->id)->pluck('id');
            $returnIds = DB::table('fabric_returns')->where('company_id', $mo->company_id)->where('production_order_id', $mo->id)->pluck('id');
            $packingIds = DB::table('packing_lists')->where('company_id', $mo->company_id)->where('production_order_id', $mo->id)->pluck('id');
            $issues = $this->ledgerRows((int) $mo->company_id, 'MATERIAL_ISSUE', 'material_issues', $issueIds);
            $returns = $this->ledgerRows((int) $mo->company_id, 'PRODUCTION_RETURN', 'fabric_returns', $returnIds);
            $receipts = $this->ledgerRows((int) $mo->company_id, 'PRODUCTION_RECEIPT', 'packing_lists', $packingIds);
            $wip = DB::table('wip_transfers')->where('company_id', $mo->company_id)->where('production_order_id', $mo->id)
                ->orderBy('id')->get(['id', 'bundle_id', 'source_scan_id', 'from_stage', 'to_stage', 'qty', 'transferred_at']);
            $scans = DB::table('production_scans')->where('company_id', $mo->company_id)->where('production_order_id', $mo->id)
                ->orderBy('id')->get(['id', 'bundle_id', 'operation_id', 'stage', 'direction', 'qty', 'scanned_at']);
            $receiptMovements = $packingIds->isEmpty() ? collect() : DB::table('stock_movements')
                ->where('company_id', $mo->company_id)->where('movement_type', 'PRODUCTION_RECEIPT')
                ->where('source_document_type', 'packing_lists')->whereIn('source_document_id', $packingIds)
                ->orderBy('id')->get(['id', 'doc_no', 'source_document_id', 'created_at']);

            return [
                'production_order' => [
                    'id' => $mo->id, 'doc_no' => $mo->doc_no, 'status' => $mo->status,
                    'company_id' => (int) $mo->company_id, 'base_currency' => $company->base_currency,
                    'qty_planned' => (float) $mo->qty_planned, 'legacy_qty_produced' => (float) $mo->qty_produced,
                    'legacy_qty_produced_authority' => 'WRITER_NOT_DEFINED; NOT_USED_AS_VALUATION_DENOMINATOR',
                ],
                'material_issue_to_wip' => [
                    'source' => $this->ledgerEvidence($issues),
                    'valuation_status' => 'NOT DEFINED', 'accounting_event' => 'MATERIAL_ISSUE',
                    'mapping_configured' => $this->mappingConfigured((int) $mo->company_id, 'MATERIAL_ISSUE'),
                    'posting_allowed' => false,
                    'reason' => 'MATERIAL_ISSUE_WIP = NOT DEFINED; material OUT cost evidence does not define a WIP valuation layer.',
                    'existing_journals' => $this->journalsForSources((int) $mo->company_id, 'material_issues', $issueIds, 'MATERIAL_ISSUE'),
                ],
                'material_return' => [
                    'source' => $this->ledgerEvidence($returns), 'valuation_status' => 'NOT DEFINED',
                    'accounting_event' => null, 'posting_allowed' => false,
                    'reason' => 'PRODUCTION_RETURN_ACCOUNTING = NOT DEFINED; mixed issue cost, WIP reversal, date, and GL event are unresolved.',
                ],
                'operational_wip' => [
                    'status' => 'DEFINED_OPERATIONAL_LINEAGE_ONLY',
                    'transfer_count' => $wip->count(), 'transferred_qty' => round((float) $wip->sum('qty'), 4),
                    'transfers' => $wip->map(fn ($row) => (array) $row)->values(),
                    'production_scans' => $scans->map(fn ($row) => (array) $row)->values(),
                    'valuation_status' => 'NOT DEFINED', 'accounting_movement_created' => false,
                ],
                'production_output_to_fg' => [
                    'source' => $this->ledgerEvidence($receipts),
                    'movements' => $receiptMovements->map(fn ($row) => (array) $row)->values(),
                    'quantity_status' => $receiptMovements->isEmpty() ? 'NOT_POSTED' : 'DEFINED_BY_ITS_PRODUCTION_RECEIPT',
                    'valuation_status' => 'NOT DEFINED', 'valuation_source' => null,
                    'accounting_event' => 'PRODUCTION_RECEIPT',
                    'mapping_configured' => $this->mappingConfigured((int) $mo->company_id, 'PRODUCTION_RECEIPT'),
                    'posting_allowed' => false,
                    'reason' => 'FG_VALUATION = NOT DEFINED; Actual Cost, standard cost, packed qty, and qty_produced are not selected as FG basis.',
                    'existing_journals' => $this->journalsForSources((int) $mo->company_id, 'packing_lists', $packingIds, 'PRODUCTION_RECEIPT'),
                ],
                'actual_cost_dependency' => [
                    'mode' => 'ITERATION_10_COMPUTED_READ_ONLY', 'persistent_document' => false,
                    'cost_per_unit' => null, 'cost_per_unit_status' => 'NOT DEFINED',
                    'wip_fg_bridge' => 'NOT DEFINED',
                ],
                'operational_reversal_accounting' => 'NOT DEFINED',
                'lineage' => 'MO → material issue/return + scans/WIP transfers + Packing List PRODUCTION_RECEIPT; valuation bridge stops before WIP/FG accounting.',
            ];
        });
    }

    public function shipmentBoundary(Shipment $shipment, User $user): array
    {
        return DB::transaction(function () use ($shipment, $user): array {
            $source = Shipment::withoutGlobalScopes()->with('packingList.productionOrder')->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $company = $this->activeCompany((int) $source->company_id);
            $this->assertAccess($user, (int) $source->company_id);
            $shipmentRows = $this->ledgerRows((int) $source->company_id, 'SHIPMENT', 'shipments', collect([$source->id]));
            $packingId = $source->packing_list_id ? collect([$source->packing_list_id]) : collect();
            $receiptRows = $this->ledgerRows((int) $source->company_id, 'PRODUCTION_RECEIPT', 'packing_lists', $packingId);
            $movement = DB::table('stock_movements')->where('company_id', $source->company_id)
                ->where('movement_type', 'SHIPMENT')->where('source_document_type', 'shipments')
                ->where('source_document_id', $source->id)->first();
            $receipt = $source->packing_list_id ? DB::table('stock_movements')->where('company_id', $source->company_id)
                ->where('movement_type', 'PRODUCTION_RECEIPT')->where('source_document_type', 'packing_lists')
                ->where('source_document_id', $source->packing_list_id)->first() : null;

            return [
                'shipment' => [
                    'id' => $source->id, 'doc_no' => $source->doc_no, 'status' => $source->status,
                    'ship_date' => $source->ship_date?->toDateString(), 'company_id' => (int) $source->company_id,
                    'base_currency' => $company->base_currency,
                ],
                'operational_source' => [
                    'packing_list_id' => $source->packing_list_id,
                    'production_order_id' => $source->packingList?->production_order_id,
                    'production_receipt' => $receipt ? ['id' => $receipt->id, 'doc_no' => $receipt->doc_no] : null,
                    'production_receipt_ledger' => $this->ledgerEvidence($receiptRows),
                    'shipment_movement' => $movement ? ['id' => $movement->id, 'doc_no' => $movement->doc_no] : null,
                    'shipment_ledger' => $this->ledgerEvidence($shipmentRows),
                ],
                'shipment_valuation' => [
                    'status' => 'NOT DEFINED', 'valuation_source' => null,
                    'posting_allowed' => false, 'reason' => 'SHIPMENT_VALUATION = NOT DEFINED',
                ],
                'cogs' => [
                    'status' => 'BLOCKED', 'accounting_event' => 'SHIPMENT_COGS',
                    'mapping_configured' => $this->mappingConfigured((int) $source->company_id, 'SHIPMENT_COGS'),
                    'amount' => null, 'valuation_source' => null, 'posting_allowed' => false,
                    'source_date_candidate' => $source->ship_date?->toDateString(),
                    'period_status' => 'NOT_APPLICABLE_WHILE_VALUATION_BLOCKED',
                    'reason' => 'COGS = NOT DEFINED; BR-083 identifies a boundary but not an authoritative amount.',
                    'existing_journals' => $this->journalsForSources((int) $source->company_id, 'shipments', collect([$source->id]), 'SHIPMENT_COGS'),
                ],
                'operational_reversal_accounting' => 'NOT DEFINED',
                'lineage' => 'Packing List → PRODUCTION_RECEIPT → FG quantity → Shipment → ITS SHIPMENT; valuation/COGS journal deliberately stops here.',
            ];
        });
    }

    private function ledgerRows(int $companyId, string $movement, string $sourceType, Collection $ids): Collection
    {
        if ($ids->isEmpty()) return collect();
        return DB::table('stock_ledger')->where('company_id', $companyId)->where('movement_type', $movement)
            ->where('source_document_type', $sourceType)->whereIn('source_document_id', $ids)->orderBy('id')
            ->get(['id', 'item_type', 'material_id', 'style_id', 'colorway_id', 'size_id', 'warehouse_id',
                'ownership', 'qty_in', 'qty_out', 'uom_id', 'unit_cost', 'total_cost',
                'source_document_id', 'source_document_line_id', 'created_at']);
    }

    private function ledgerEvidence(Collection $rows): array
    {
        $companyRows = $rows->where('ownership', 'COMPANY')->values();
        $valued = $companyRows->isNotEmpty() && $companyRows->every(fn ($row) => $row->unit_cost !== null && $row->total_cost !== null);
        return [
            'row_count' => $rows->count(), 'company_owned_row_count' => $companyRows->count(),
            'qty_in' => round((float) $rows->sum('qty_in'), 4), 'qty_out' => round((float) $rows->sum('qty_out'), 4),
            'stored_cost_complete' => $valued,
            'stored_cost_total' => $valued ? round((float) $companyRows->sum('total_cost'), 4) : null,
            'cost_use' => 'SOURCE_EVIDENCE_ONLY_NOT_WIP_FG_OR_COGS_AUTHORITY',
            'rows' => $rows->map(fn ($row) => (array) $row)->values(),
        ];
    }

    private function mappingConfigured(int $companyId, string $event): bool
    {
        return AccountMapping::withoutGlobalScopes()->where('company_id', $companyId)->where('event', $event)->exists();
    }

    private function journalsForSources(int $companyId, string $sourceType, Collection $ids, string $event): array
    {
        if ($ids->isEmpty()) return [];
        return Journal::withoutGlobalScopes()->where('company_id', $companyId)->where('event', $event)
            ->where('source_document_type', $sourceType)->whereIn('source_document_id', $ids)
            ->orderBy('id')->get(['id', 'doc_no', 'status', 'period', 'journal_date', 'source_document_id'])
            ->map(fn ($journal) => $journal->toArray())->values()->all();
    }

    private function activeCompany(int $companyId): object
    {
        $company = DB::table('companies')->where('id', $companyId)->whereNull('deleted_at')->first();
        if ($company === null || ! (bool) $company->is_active) throw new RuntimeException('Company Finance tidak aktif.');
        return $company;
    }

    private function assertAccess(User $user, int $companyId): void
    {
        if ((int) $user->company_id !== $companyId && ! $user->companies()->whereKey($companyId)->exists()) {
            throw new RuntimeException('User tidak memiliki akses ke company Finance.');
        }
    }
}
