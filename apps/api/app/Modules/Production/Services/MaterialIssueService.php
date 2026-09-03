<?php

namespace Modules\Production\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockLedger;
use Modules\Inventory\Models\StockReservation;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\MasterData\Models\Material;
use Modules\MasterData\Services\UomConversionService;
use Modules\Production\Models\FabricReturn;
use Modules\Production\Models\MaterialIssue;
use Modules\Production\Models\MoMaterialAllocation;
use Modules\Production\Models\ProductionOrder;
use Modules\Receiving\Models\FabricRoll;
use RuntimeException;

class MaterialIssueService
{
    public function __construct(
        private NumberingService $numbering,
        private InventoryTransactionService $its,
        private AuditService $audit,
        private UomConversionService $uoms,
        private NamedProductionMeasureService $measures,
    ) {}

    public function issue(ProductionOrder $mo, int $warehouseId, array $lines, User $user): MaterialIssue
    {
        if ($lines === []) throw new RuntimeException('Material issue wajib punya minimal 1 line.');
        return DB::transaction(function () use ($mo, $warehouseId, $lines, $user): MaterialIssue {
            $locked = ProductionOrder::withoutGlobalScopes()->whereKey($mo->id)->lockForUpdate()->firstOrFail();
            $this->assertAccess($locked, $warehouseId, $user);
            if (! in_array($locked->status, ['RELEASED', 'CUTTING', 'SEWING'], true)) throw new RuntimeException("Issue hanya untuk MO RELEASED/CUTTING/SEWING (status: {$locked->status}).");
            $seen = []; $resolved = [];
            foreach ($lines as $line) {
                $qty = (float) ($line['qty'] ?? 0); if ($qty <= 0) throw new RuntimeException('Qty issue wajib lebih besar dari nol.');
                $material = Material::withoutGlobalScopes()->where('company_id', $locked->company_id)->whereKey((int) ($line['material_id'] ?? 0))->first();
                if (! $material) throw new RuntimeException('Material issue tidak ditemukan pada company MO.');
                $allocation = MoMaterialAllocation::where('production_order_id', $locked->id)->where('material_id', $material->id)->lockForUpdate()->first();
                if (! $allocation) throw new RuntimeException('BR-066: material tidak memiliki allocation snapshot pada MO.');
                if ((bool) $allocation->is_backflush || $this->hasPostedMode($locked->id, $material->id, 'BACKFLUSH')) throw new RuntimeException('BR-066: material BACKFLUSH tidak boleh diposting melalui ACTUAL issue.');
                if (! $allocation->uom_id) throw new RuntimeException('BR-066: UOM allocation material belum tersedia; issue gagal tertutup.');
                $rollId = ! empty($line['roll_id']) ? (int) $line['roll_id'] : null;
                if ($material->isRollTracked() && $rollId === null) throw new RuntimeException("BR-041: fabric [{$material->code}] wajib di-issue per roll.");
                if ($rollId && FabricReturn::withoutGlobalScopes()->where('production_order_id', $locked->id)->where('roll_id', $rollId)->exists()) throw new RuntimeException('Roll yang sudah ditutup dengan leftover return tidak dapat di-issue ulang ke MO yang sama.');
                $key = $material->id.':'.($rollId ?? 'none').':'.($line['lot_no'] ?? '').':'.($line['location_id'] ?? '');
                if (isset($seen[$key])) throw new RuntimeException('Line material issue duplikat.'); $seen[$key] = true;
                $query = StockReservation::withoutGlobalScopes()->where('company_id', $locked->company_id)->where('mo_id', $locked->id)
                    ->where('warehouse_id', $warehouseId)->where('material_id', $material->id)->whereIn('status', ['ACTIVE', 'PARTIAL_ISSUED']);
                if ($rollId) $query->where('roll_id', $rollId); else $query->whereNull('roll_id')->where('lot_no', $line['lot_no'] ?? null)->where('location_id', $line['location_id'] ?? null);
                $reservations = $query->lockForUpdate()->get();
                if ($reservations->count() !== 1) throw new RuntimeException("BR-060: reservation harus ditemukan tepat satu untuk material #{$material->id} dan dimensi stok yang dipilih.");
                $reservation = $reservations->first(); if ($reservation->remaining() + 0.0001 < $qty) throw new RuntimeException("BR-060: issue {$qty} melebihi sisa reservasi {$reservation->remaining()} untuk material #{$material->id}.");
                if ($rollId) { $roll = FabricRoll::withoutGlobalScopes()->where('company_id', $locked->company_id)->where('material_id', $material->id)->whereKey($rollId)->lockForUpdate()->first(); if (! $roll || $roll->status !== 'RELEASED') throw new RuntimeException('Roll issue tidak valid atau belum RELEASED.'); }
                $uomId = (int) $allocation->uom_id; if (isset($line['uom_id']) && (int) $line['uom_id'] !== $uomId) throw new RuntimeException('BR-066: UOM ACTUAL issue tidak sesuai allocation snapshot.');
                $resolved[] = compact('qty', 'material', 'allocation', 'reservation', 'uomId');
            }

            $issue = MaterialIssue::create(['company_id' => $locked->company_id, 'doc_no' => $this->numbering->next($locked->company_id, 'MI'),
                'production_order_id' => $locked->id, 'warehouse_id' => $warehouseId, 'mode' => 'ACTUAL', 'status' => 'POSTED', 'created_by' => $user->id]);
            $itsLines = [];
            foreach ($resolved as $item) {
                $reservation = $item['reservation']; $qty = $item['qty'];
                $issueLine = $issue->lines()->create(['material_id' => $item['material']->id, 'stock_reservation_id' => $reservation->id,
                    'roll_id' => $reservation->roll_id, 'lot_no' => $reservation->lot_no, 'qty' => $qty, 'uom_id' => $item['uomId'], 'backflush_stage' => null]);
                $itsLine = ['material_id' => $item['material']->id, 'warehouse_id' => $warehouseId, 'location_id' => $reservation->location_id,
                    'roll_id' => $reservation->roll_id, 'lot_no' => $reservation->lot_no, 'ownership' => $reservation->ownership,
                    'qty' => $qty, 'uom_id' => $item['uomId'], 'source_document_line_id' => $issueLine->id];
                $unitCost = $this->companyMovingAverageCost((int) $locked->company_id, $warehouseId, $reservation); if ($unitCost !== null) $itsLine['unit_cost'] = $unitCost;
                $itsLines[] = $itsLine; $this->recordIssued($reservation, $qty);
                if ($reservation->roll_id) $this->recordDispatch((int) $locked->company_id, (int) $locked->id, (int) $reservation->roll_id, $warehouseId, (int) $item['uomId'], $qty);
                $item['allocation']->increment('qty_issued', $qty);
            }
            $this->its->post('MATERIAL_ISSUE', ['company_id' => $locked->company_id, 'source_document_type' => 'material_issues', 'source_document_id' => $issue->id], $itsLines, $user);
            $this->audit->record('create', $issue, after: ['doc_no' => $issue->doc_no, 'mode' => 'ACTUAL', 'lines' => count($resolved), 'policy' => 'BR-066']);
            return $issue->load('lines');
        });
    }

    public function backflush(ProductionOrder $mo, int $warehouseId, User $user): ?MaterialIssue
    {
        return DB::transaction(function () use ($mo, $warehouseId, $user): ?MaterialIssue {
            $locked = ProductionOrder::withoutGlobalScopes()->whereKey($mo->id)->lockForUpdate()->firstOrFail();
            $this->assertAccess($locked, $warehouseId, $user);
            if (! in_array($locked->status, ['RELEASED', 'CUTTING', 'SEWING', 'FINISHING', 'QC', 'PACKED'], true)) throw new RuntimeException('Status MO tidak mengizinkan backflush.');
            $allocations = MoMaterialAllocation::with('material')->where('production_order_id', $locked->id)->where('is_backflush', true)->lockForUpdate()->get();
            if ($allocations->isEmpty()) return null;
            if ((float) $locked->qty_planned <= 0) throw new RuntimeException('BR-066: qty_planned MO tidak valid.');
            $resolved = []; $usedStages = [];
            foreach ($allocations as $allocation) {
                $material = $allocation->material; $stage = $allocation->backflush_stage; $uomId = (int) $allocation->uom_id;
                if (! $material || $material->isFabric()) throw new RuntimeException('BR-066: fabric tidak boleh BACKFLUSH.');
                if (! $stage || ! in_array($stage, NamedProductionMeasureService::BACKFLUSH_STAGES, true)) throw new RuntimeException('BR-066: Named Stage BACKFLUSH belum tersedia.');
                if (! $uomId || ! $material->use_uom_id || $uomId !== (int) $material->use_uom_id) throw new RuntimeException('BR-066: UOM source BACKFLUSH hilang atau tidak sesuai.');
                if ($this->hasPostedMode($locked->id, $material->id, 'ACTUAL')) throw new RuntimeException('BR-066: ACTUAL dan BACKFLUSH overlap pada material yang sama.');
                $measure = $this->measures->measure($locked, $stage); if ($measure['status'] !== 'DEFINED' || $measure['qty'] === null) throw new RuntimeException("BR-066: Named Stage {$stage} belum memiliki source authority.");
                $targetQty = round(((float) $allocation->qty_required / (float) $locked->qty_planned) * (float) $measure['qty'], 4);
                $already = (float) DB::table('material_issue_lines')->join('material_issues', 'material_issues.id', '=', 'material_issue_lines.material_issue_id')
                    ->where('material_issues.production_order_id', $locked->id)->where('material_issues.mode', 'BACKFLUSH')
                    ->where('material_issues.status', 'POSTED')->where('material_issue_lines.material_id', $material->id)->sum('material_issue_lines.qty');
                $remaining = round($targetQty - $already, 4); if ($remaining <= 0) continue;
                $reservations = StockReservation::withoutGlobalScopes()->where('company_id', $locked->company_id)->where('mo_id', $locked->id)
                    ->where('warehouse_id', $warehouseId)->where('material_id', $material->id)->whereIn('status', ['ACTIVE', 'PARTIAL_ISSUED'])->orderBy('id')->lockForUpdate()->get();
                foreach ($reservations as $reservation) { if ($remaining <= 0) break; $qty = min($reservation->remaining(), $remaining); if ($qty <= 0) continue;
                    $resolved[] = compact('allocation', 'material', 'stage', 'uomId', 'reservation', 'qty'); $remaining = round($remaining - $qty, 4); }
                if ($remaining > 0.0001) throw new RuntimeException("BR-060: reservation backflush tidak cukup untuk material #{$material->id}.");
                $usedStages[$stage] = true;
            }
            if ($resolved === []) return null;
            $issue = MaterialIssue::create(['company_id' => $locked->company_id, 'doc_no' => $this->numbering->next($locked->company_id, 'MI'),
                'production_order_id' => $locked->id, 'warehouse_id' => $warehouseId, 'mode' => 'BACKFLUSH', 'status' => 'POSTED', 'created_by' => $user->id]);
            $itsLines = [];
            foreach ($resolved as $item) {
                $reservation = $item['reservation'];
                $line = $issue->lines()->create(['material_id' => $item['material']->id, 'stock_reservation_id' => $reservation->id,
                    'roll_id' => $reservation->roll_id, 'lot_no' => $reservation->lot_no, 'qty' => $item['qty'], 'uom_id' => $item['uomId'], 'backflush_stage' => $item['stage']]);
                $itsLine = ['material_id' => $item['material']->id, 'warehouse_id' => $warehouseId, 'location_id' => $reservation->location_id,
                    'roll_id' => $reservation->roll_id, 'lot_no' => $reservation->lot_no, 'ownership' => $reservation->ownership,
                    'qty' => $item['qty'], 'uom_id' => $item['uomId'], 'source_document_line_id' => $line->id];
                $unitCost = $this->companyMovingAverageCost((int) $locked->company_id, $warehouseId, $reservation); if ($unitCost !== null) $itsLine['unit_cost'] = $unitCost;
                $itsLines[] = $itsLine; $this->recordIssued($reservation, $item['qty']); $item['allocation']->increment('qty_issued', $item['qty']);
            }
            $this->its->post('MATERIAL_ISSUE', ['company_id' => $locked->company_id, 'source_document_type' => 'material_issues', 'source_document_id' => $issue->id], $itsLines, $user);
            $this->audit->record('create', $issue, after: ['doc_no' => $issue->doc_no, 'mode' => 'BACKFLUSH', 'named_stages' => array_keys($usedStages), 'policy' => 'BR-065/066']);
            return $issue->load('lines');
        });
    }

    public function returnLeftover(ProductionOrder $mo, FabricRoll $roll, int $warehouseId, User $user, ?float $inputQty = null, ?int $inputUomId = null, ?string $reason = null): FabricReturn
    {
        return DB::transaction(function () use ($mo, $roll, $warehouseId, $user, $inputQty, $inputUomId, $reason): FabricReturn {
            $lockedMo = ProductionOrder::withoutGlobalScopes()->whereKey($mo->id)->lockForUpdate()->firstOrFail(); $this->assertAccess($lockedMo, $warehouseId, $user);
            $lockedRoll = FabricRoll::withoutGlobalScopes()->with('material')->where('company_id', $lockedMo->company_id)->whereKey($roll->id)->lockForUpdate()->firstOrFail();
            if (FabricReturn::withoutGlobalScopes()->where('production_order_id', $lockedMo->id)->where('roll_id', $lockedRoll->id)->exists()) throw new RuntimeException('Leftover roll untuk MO ini sudah dikembalikan.');
            $dispatch = DB::table('fabric_dispatch_balances')->where('company_id', $lockedMo->company_id)->where('production_order_id', $lockedMo->id)->where('roll_id', $lockedRoll->id)->lockForUpdate()->first();
            if (! $dispatch) throw new RuntimeException('Roll belum pernah di-dispatch ke MO ini.'); if ((int) $dispatch->warehouse_id !== $warehouseId) throw new RuntimeException('Return wajib ke warehouse asal dispatch.');
            $available = round((float) $dispatch->qty_dispatched - (float) $dispatch->qty_consumed - (float) $dispatch->qty_returned, 4); if ($available <= 0) throw new RuntimeException('Tidak ada leftover dispatched yang dapat dikembalikan.');
            $uomId = (int) $dispatch->uom_id; $qtyUse = $inputQty === null ? $available : $this->uoms->convert((int) $lockedMo->company_id, (int) $lockedRoll->material_id, (float) $inputQty, (int) ($inputUomId ?: $uomId), $uomId);
            if (abs($qtyUse - $available) > 0.0001) throw new RuntimeException("Return harus menutup seluruh leftover dispatched ({$available})."); if ($qtyUse - $lockedRoll->remainingUse() > 0.0001) throw new RuntimeException('Return melebihi sisa fisik roll.');
            $meters = $this->uoms->toMeters((int) $lockedMo->company_id, $uomId, $qtyUse);
            $return = FabricReturn::create(['company_id' => $lockedMo->company_id, 'doc_no' => $this->numbering->next($lockedMo->company_id, 'MI'), 'production_order_id' => $lockedMo->id,
                'roll_id' => $lockedRoll->id, 'warehouse_id' => $warehouseId, 'uom_id' => $uomId, 'qty_returned_meter' => $meters, 'qty_returned_use' => $qtyUse,
                'qty_dispatched_use' => $dispatch->qty_dispatched, 'qty_consumed_use' => $dispatch->qty_consumed, 'reason' => $reason, 'created_by' => $user->id]);
            $line = ['material_id' => $lockedRoll->material_id, 'warehouse_id' => $warehouseId, 'roll_id' => $lockedRoll->id, 'lot_no' => $lockedRoll->lot_no, 'qty' => $qtyUse, 'uom_id' => $uomId];
            $cost = $this->unambiguousIssuedUnitCost((int) $lockedMo->company_id, (int) $lockedMo->id, (int) $lockedRoll->id, $warehouseId); if ($cost !== null) $line['unit_cost'] = $cost;
            $this->its->post('PRODUCTION_RETURN', ['company_id' => $lockedMo->company_id, 'source_document_type' => 'fabric_returns', 'source_document_id' => $return->id], [$line], $user);
            DB::table('fabric_dispatch_balances')->where('id', $dispatch->id)->update(['qty_returned' => (float) $dispatch->qty_returned + $qtyUse, 'updated_at' => now()]);
            $this->audit->record('create', $return, after: ['doc_no' => $return->doc_no, 'qty' => $qtyUse, 'uom_id' => $uomId]); return $return;
        });
    }

    private function hasPostedMode(int $moId, int $materialId, string $mode): bool
    {
        return DB::table('material_issue_lines')->join('material_issues', 'material_issues.id', '=', 'material_issue_lines.material_issue_id')
            ->where('material_issues.production_order_id', $moId)->where('material_issues.mode', $mode)->where('material_issues.status', 'POSTED')
            ->where('material_issue_lines.material_id', $materialId)->exists();
    }

    private function companyMovingAverageCost(int $companyId, int $warehouseId, StockReservation $reservation): ?float
    {
        if ($reservation->ownership !== 'COMPANY') return null;
        $value = StockBalance::withoutGlobalScopes()->where(['company_id' => $companyId, 'item_type' => 'MATERIAL', 'material_id' => $reservation->material_id,
            'style_id' => null, 'colorway_id' => null, 'size_id' => null, 'warehouse_id' => $warehouseId, 'location_id' => $reservation->location_id,
            'lot_no' => $reservation->lot_no, 'roll_id' => $reservation->roll_id, 'ownership' => 'COMPANY'])->value('avg_cost');
        return $value === null ? null : (float) $value;
    }

    private function unambiguousIssuedUnitCost(int $companyId, int $moId, int $rollId, int $warehouseId): ?float
    {
        $rows = StockLedger::withoutGlobalScopes()->join('material_issues', 'material_issues.id', '=', 'stock_ledger.source_document_id')
            ->where('stock_ledger.company_id', $companyId)->where('stock_ledger.movement_type', 'MATERIAL_ISSUE')->where('stock_ledger.source_document_type', 'material_issues')
            ->where('material_issues.production_order_id', $moId)->where('stock_ledger.roll_id', $rollId)->where('stock_ledger.warehouse_id', $warehouseId)
            ->where('stock_ledger.ownership', 'COMPANY')->get(['stock_ledger.unit_cost']);
        if ($rows->isEmpty() || $rows->contains(fn ($row) => $row->unit_cost === null)) return null;
        $costs = $rows->map(fn ($row) => number_format((float) $row->unit_cost, 6, '.', ''))->unique(); return $costs->count() === 1 ? (float) $costs->first() : null;
    }

    private function recordDispatch(int $companyId, int $moId, int $rollId, int $warehouseId, int $uomId, float $qty): void
    {
        DB::table('fabric_dispatch_balances')->insertOrIgnore(['company_id' => $companyId, 'production_order_id' => $moId, 'roll_id' => $rollId, 'warehouse_id' => $warehouseId,
            'uom_id' => $uomId, 'qty_dispatched' => 0, 'qty_consumed' => 0, 'qty_returned' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('fabric_dispatch_balances')->where('production_order_id', $moId)->where('roll_id', $rollId)->lockForUpdate()->firstOrFail();
        if ((int) $row->company_id !== $companyId || (int) $row->warehouse_id !== $warehouseId || (int) $row->uom_id !== $uomId) throw new RuntimeException('Dimensi dispatch roll berubah dan ditolak.');
        DB::table('fabric_dispatch_balances')->where('id', $row->id)->update(['qty_dispatched' => (float) $row->qty_dispatched + $qty, 'updated_at' => now()]);
    }

    private function recordIssued(StockReservation $reservation, float $qty): void
    {
        $reservation->qty_issued = (float) $reservation->qty_issued + $qty; $reservation->status = $reservation->remaining() <= 0.0001 ? 'FULLY_ISSUED' : 'PARTIAL_ISSUED'; $reservation->save();
    }

    private function assertAccess(ProductionOrder $mo, int $warehouseId, User $user): void
    {
        if ((int) $user->company_id !== (int) $mo->company_id && ! $user->companies()->whereKey($mo->company_id)->exists()) throw new RuntimeException('User tidak memiliki akses ke company MO.');
        if (! DB::table('warehouses')->where('id', $warehouseId)->where('company_id', $mo->company_id)->exists()) throw new RuntimeException('Warehouse tidak ditemukan pada company MO.');
    }
}
