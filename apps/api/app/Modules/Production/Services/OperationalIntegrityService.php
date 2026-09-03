<?php

namespace Modules\Production\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Production\Models\ProductionOrder;
use RuntimeException;

/** Read-only convergence evidence. Historical facts are never rewritten. */
class OperationalIntegrityService
{
    public function authorityMatrix(int $companyId, User $user): array
    {
        $this->assertAccess($user, $companyId); $this->assertActive($companyId);
        return ['rows' => [
            $this->row('Fabric consumption', 'Marker Log', 'Lay/Lay Roll', 'Marker remains historical/read-only; Lay Roll is the only new writer', 'LOCKED', 'BR-031'),
            $this->row('Production output', 'qty_produced', 'Separate Named Measures', 'Generic whole-MO output is prohibited', 'LOCKED', 'BR-065'),
            $this->row('Material consumption method', 'ACTUAL/BACKFLUSH overlap', 'Exclusive per MO material', 'BACKFLUSH requires one configured Named Stage', 'LOCKED', 'BR-066'),
            $this->row('Inventory movement', 'Direct domain callers', 'ITS', 'No parallel ledger or movement type added', 'CONVERGED', 'Preserve ITS idempotency'),
            $this->row('Packing Input', 'Nullable historical QC source', 'QC FINAL PASS', 'Historical rows remain readable', 'COMPATIBILITY', 'No backfill'),
        ], 'states' => [
            'MARKER_LAY_CONSUMPTION_AUTHORITY' => 'LAY_ROLL', 'NEW_MARKER_CONSUMPTION_WRITES' => 'BLOCKED',
            'HISTORICAL_CONSUMPTION_REWRITE' => 'PROHIBITED', 'PRODUCTION_OUTPUT_AUTHORITY' => 'SEPARATE_NAMED_MEASURES',
            'QTY_PRODUCED' => 'LEGACY COMPATIBILITY — NOT AUTHORITY OR FALLBACK', 'BACKFLUSH_CONVERGENCE' => 'NAMED_STAGE + EXCLUSIVE_PER_MATERIAL',
            'INVENTORY_AUTHORITY' => 'ITS', 'ACCOUNTING_AUTHORITY' => 'EXISTING GL / JOURNALS',
            'WIP_VALUATION' => 'NOT IMPLEMENTED IN BATCH 1', 'FG_VALUATION' => 'NOT IMPLEMENTED IN BATCH 1',
            'SHIPMENT_VALUATION' => 'NOT IMPLEMENTED IN BATCH 1', 'COGS' => 'NOT IMPLEMENTED IN BATCH 1',
        ], 'safe_guards' => [
            'Legacy Marker endpoint remains mounted but rejects new writes.',
            'Lay Roll remains blocked on MOs with historical Marker evidence to avoid creating mixed history.',
            'ACTUAL and BACKFLUSH issue modes reject overlap per MO material.',
            'BACKFLUSH reads one snapshotted Named Stage and posts through existing Material Issue → ITS.',
        ], 'writes_performed' => false, 'migration' => '2026_09_03_000028_lock_material_consumption_authority'];
    }

    public function inspect(ProductionOrder $productionOrder, User $user): array
    {
        $mo = ProductionOrder::withoutGlobalScopes()->whereKey($productionOrder->id)->firstOrFail(); $this->assertAccess($user, (int) $mo->company_id); $this->assertActive((int) $mo->company_id);
        $markers = DB::table('marker_logs as marker')->join('cut_orders as cut', 'cut.id', '=', 'marker.cut_order_id')->where('cut.company_id', $mo->company_id)
            ->where('cut.production_order_id', $mo->id)->orderBy('marker.id')->get(['marker.id', 'marker.cut_order_id', 'marker.roll_id', 'marker.qty_fabric_used_use', 'marker.qty_fabric_used_m', 'marker.created_at']);
        $layRolls = DB::table('lay_rolls as line')->join('lays as lay', 'lay.id', '=', 'line.lay_id')->join('cut_orders as cut', 'cut.id', '=', 'lay.cut_order_id')
            ->where('cut.company_id', $mo->company_id)->where('cut.production_order_id', $mo->id)->orderBy('line.id')
            ->get(['line.id', 'line.lay_id', 'line.fabric_roll_id', 'line.qty_used', 'line.shade_override', 'lay.cut_order_id', 'lay.lay_no', 'lay.status as lay_status', 'line.created_at']);
        $mixed = $markers->isNotEmpty() && $layRolls->isNotEmpty();
        $issues = DB::table('material_issues')->where('company_id', $mo->company_id)->where('production_order_id', $mo->id)->orderBy('id')->get(['id', 'doc_no', 'mode', 'status', 'warehouse_id']);
        $issueLines = $issues->isEmpty() ? collect() : DB::table('material_issue_lines')->whereIn('material_issue_id', $issues->pluck('id'))->orderBy('id')->get();
        $modes = $issueLines->map(function ($line) use ($issues): array { $issue = $issues->firstWhere('id', $line->material_issue_id); return ['material_id' => (int) $line->material_id,
            'mode' => $issue?->mode, 'qty' => (float) $line->qty, 'backflush_stage' => $line->backflush_stage]; })->groupBy('material_id')->map(fn ($rows) => [
                'modes' => $rows->pluck('mode')->filter()->unique()->values(), 'named_stages' => $rows->pluck('backflush_stage')->filter()->unique()->values(),
                'qty' => round((float) $rows->sum('qty'), 4), 'actual_backflush_overlap' => $rows->pluck('mode')->contains('ACTUAL') && $rows->pluck('mode')->contains('BACKFLUSH')]);
        $named = app(NamedProductionMeasureService::class)->all($mo);
        $path = $mixed ? '[HISTORICAL CONFLICT: Marker + Lay Roll]' : ($layRolls->isNotEmpty() ? 'Lay → Lay Roll' : ($markers->isNotEmpty() ? 'Legacy Marker' : '[not executed]'));
        return ['production_order' => ['id' => $mo->id, 'doc_no' => $mo->doc_no, 'status' => $mo->status, 'company_id' => (int) $mo->company_id],
            'authority_conflict' => ['domain' => 'FABRIC_CONSUMPTION', 'marker_present' => $markers->isNotEmpty(), 'lay_roll_present' => $layRolls->isNotEmpty(),
                'mixed_path' => $mixed, 'status' => $mixed ? 'HISTORICAL_CONFLICT' : ($markers->isNotEmpty() ? 'LEGACY_READ_ONLY' : ($layRolls->isNotEmpty() ? 'AUTHORITATIVE' : 'NOT_EXECUTED')),
                'new_authority' => 'LAY_ROLL', 'new_marker_writes' => 'BLOCKED', 'historical_mutation' => false, 'authority_resolution' => 'BR-031 LOCKED'],
            'consumption_evidence' => ['markers' => $markers->map(fn ($row) => (array) $row)->values(), 'lay_rolls' => $layRolls->map(fn ($row) => (array) $row)->values()],
            'qty_produced' => ['stored_value' => (float) $mo->qty_produced, 'classification' => 'LEGACY COMPATIBILITY — NOT AUTHORITATIVE', 'writer' => 'NOT FOUND',
                'production_output_authority' => 'SEPARATE_NAMED_MEASURES', 'named_measures' => $named],
            'backflush' => ['endpoint' => 'POST production/orders/{productionOrder}/issues/backflush', 'status' => 'LOCKED', 'uses_qty_produced' => false,
                'writes_inventory_through' => 'MaterialIssue → ITS MATERIAL_ISSUE', 'bypasses_its' => false, 'bypasses_reservation' => false,
                'actual_backflush_overlap' => $modes->contains(fn ($row) => $row['actual_backflush_overlap']), 'material_evidence' => $modes,
                'convergence' => 'BR-066 — exclusive per material + one snapshotted Named Stage'],
            'inventory' => ['authority' => 'ITS', 'fabric_dispatch_note' => 'Lay Roll dispatch consumption is an operational control, not a second stock ledger.'],
            'operational_chain' => ['cutting_path' => $path, 'named_measures' => $named],
            'legacy_endpoints' => [['endpoint' => 'POST cutting/orders/{cutOrder}/markers', 'action' => 'PRESERVED + NEW WRITES BLOCKED'],
                ['endpoint' => 'POST production/orders/{productionOrder}/issues/backflush', 'action' => 'PRESERVED + BR-066 ENFORCED']],
            'lineage' => ['forward' => "MO → Cutting → {$path} → separate named measures → downstream consumer", 'history_policy' => 'No backfill or rewrite.'],
            'resolved_conflicts' => ['Lay Roll is sole new fabric-consumption writer.', 'qty_produced is not a fallback.', 'ACTUAL/BACKFLUSH overlap is rejected.'],
            'decision_required' => ['D-05 through D-11 implementation is outside Batch 1.'], 'writes_performed' => false,
            'migration' => '2026_09_03_000028_lock_material_consumption_authority'];
    }

    private function row(string $domain, string $legacy, string $current, string $conflict, string $status, string $resolution): array { return compact('domain', 'legacy', 'current', 'conflict', 'status', 'resolution'); }
    private function assertActive(int $companyId): void { if (! DB::table('companies')->where('id', $companyId)->where('is_active', true)->whereNull('deleted_at')->exists()) throw new RuntimeException('Company operational integrity tidak aktif.'); }
    private function assertAccess(User $user, int $companyId): void { if ((int) $user->company_id !== $companyId && ! $user->companies()->whereKey($companyId)->exists()) throw new RuntimeException('User tidak memiliki akses ke company operational integrity.'); }
}
