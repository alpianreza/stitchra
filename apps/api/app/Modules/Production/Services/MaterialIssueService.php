<?php

namespace Modules\Production\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Inventory\Models\StockReservation;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\MasterData\Models\Material;
use Modules\Production\Models\FabricReturn;
use Modules\Production\Models\MaterialIssue;
use Modules\Production\Models\ProductionOrder;
use Modules\Receiving\Models\FabricRoll;
use RuntimeException;

class MaterialIssueService
{
    public function __construct(
        private NumberingService $numbering,
        private InventoryTransactionService $its,
        private AuditService $audit,
    ) {}

    public function issue(ProductionOrder $mo, int $warehouseId, array $lines, User $user): MaterialIssue
    {
        if ($lines === []) throw new RuntimeException('Material issue wajib punya minimal 1 line.');

        return DB::transaction(function () use ($mo, $warehouseId, $lines, $user): MaterialIssue {
            $locked = ProductionOrder::withoutGlobalScopes()->whereKey($mo->id)->lockForUpdate()->firstOrFail();
            $this->assertAccess($locked, $warehouseId, $user);
            if (! in_array($locked->status, ['RELEASED', 'CUTTING', 'SEWING'], true)) {
                throw new RuntimeException("Issue hanya untuk MO RELEASED/CUTTING/SEWING (status: {$locked->status}).");
            }

            $seen = [];
            $resolved = [];
            foreach ($lines as $line) {
                $qty = (float) ($line['qty'] ?? 0);
                if ($qty <= 0) throw new RuntimeException('Qty issue wajib lebih besar dari nol.');
                $material = Material::withoutGlobalScopes()->where('company_id', $locked->company_id)
                    ->whereKey((int) ($line['material_id'] ?? 0))->first();
                if ($material === null) throw new RuntimeException('Material issue tidak ditemukan pada company MO.');

                $rollId = ! empty($line['roll_id']) ? (int) $line['roll_id'] : null;
                if ($material->isRollTracked() && $rollId === null) {
                    throw new RuntimeException("BR-041: fabric [{$material->code}] wajib di-issue per roll.");
                }
                $key = $material->id.':'.($rollId ?? 'none').':'.($line['lot_no'] ?? '').':'.($line['location_id'] ?? '');
                if (isset($seen[$key])) throw new RuntimeException('Line material issue duplikat.');
                $seen[$key] = true;

                $query = StockReservation::withoutGlobalScopes()
                    ->where('company_id', $locked->company_id)->where('mo_id', $locked->id)
                    ->where('warehouse_id', $warehouseId)->where('material_id', $material->id)
                    ->whereIn('status', ['ACTIVE', 'PARTIAL_ISSUED']);
                if ($rollId !== null) $query->where('roll_id', $rollId);
                else $query->whereNull('roll_id')->where('lot_no', $line['lot_no'] ?? null)->where('location_id', $line['location_id'] ?? null);
                $reservations = $query->lockForUpdate()->get();
                if ($reservations->count() !== 1) {
                    throw new RuntimeException("BR-060: reservation harus ditemukan tepat satu untuk material #{$material->id} dan dimensi stok yang dipilih.");
                }
                $reservation = $reservations->first();
                if ($reservation->remaining() + 0.0001 < $qty) {
                    throw new RuntimeException("BR-060: issue {$qty} melebihi sisa reservasi {$reservation->remaining()} untuk material #{$material->id}.");
                }

                if ($rollId !== null) {
                    $roll = FabricRoll::withoutGlobalScopes()->where('company_id', $locked->company_id)
                        ->where('material_id', $material->id)->whereKey($rollId)->lockForUpdate()->first();
                    if ($roll === null || $roll->status !== 'RELEASED') {
                        throw new RuntimeException('Roll issue tidak valid atau belum RELEASED.');
                    }
                }
                $uomId = $material->isRollTracked() ? $material->use_uom_id : (int) ($line['uom_id'] ?? 0);
                if (! $uomId || (int) ($line['uom_id'] ?? $uomId) !== (int) $uomId) {
                    throw new RuntimeException('UOM issue tidak sesuai UOM stok material.');
                }

                $resolved[] = compact('line', 'qty', 'material', 'reservation', 'uomId');
            }

            $issue = MaterialIssue::create([
                'company_id' => $locked->company_id, 'doc_no' => $this->numbering->next($locked->company_id, 'MI'),
                'production_order_id' => $locked->id, 'warehouse_id' => $warehouseId,
                'mode' => 'ACTUAL', 'status' => 'POSTED', 'created_by' => $user->id,
            ]);
            $itsLines = [];
            foreach ($resolved as $item) {
                $line = $item['line']; $reservation = $item['reservation']; $qty = $item['qty'];
                $issueLine = $issue->lines()->create([
                    'material_id' => $item['material']->id, 'stock_reservation_id' => $reservation->id,
                    'roll_id' => $reservation->roll_id, 'lot_no' => $reservation->lot_no,
                    'qty' => $qty, 'uom_id' => $item['uomId'],
                ]);
                $itsLines[] = [
                    'material_id' => $item['material']->id, 'warehouse_id' => $warehouseId,
                    'location_id' => $reservation->location_id, 'roll_id' => $reservation->roll_id,
                    'lot_no' => $reservation->lot_no, 'ownership' => $reservation->ownership,
                    'qty' => $qty, 'uom_id' => $item['uomId'], 'source_document_line_id' => $issueLine->id,
                ];
                $this->recordIssued($reservation, $qty);
                $locked->materialAllocations()->where('material_id', $item['material']->id)->increment('qty_issued', $qty);
            }
            $this->its->post('MATERIAL_ISSUE', [
                'company_id' => $locked->company_id, 'source_document_type' => 'material_issues', 'source_document_id' => $issue->id,
            ], $itsLines, $user);
            $this->audit->record('create', $issue, after: ['doc_no' => $issue->doc_no, 'lines' => count($resolved)]);
            return $issue->load('lines');
        });
    }

    public function backflush(ProductionOrder $mo, int $warehouseId, User $user): ?MaterialIssue
    {
        return DB::transaction(function () use ($mo, $warehouseId, $user): ?MaterialIssue {
            $locked = ProductionOrder::withoutGlobalScopes()->with('bomVersion.lines')
                ->whereKey($mo->id)->lockForUpdate()->firstOrFail();
            $this->assertAccess($locked, $warehouseId, $user);
            if (! in_array($locked->status, ['RELEASED', 'CUTTING', 'SEWING', 'FINISHING'], true)) {
                throw new RuntimeException('Status MO tidak mengizinkan backflush.');
            }
            $qtyProduced = (float) $locked->qty_produced;
            if ($qtyProduced <= 0) throw new RuntimeException('Backflush memerlukan qty_produced > 0 di MO.');

            $targets = [];
            foreach ($locked->bomVersion->lines->where('is_backflush', true) as $bomLine) {
                $targets[$bomLine->material_id] ??= ['qty' => 0.0, 'uom_id' => $bomLine->uom_id];
                $targets[$bomLine->material_id]['qty'] += $bomLine->grossPerPcs() * $qtyProduced;
            }
            if ($targets === []) return null;

            $resolved = [];
            foreach ($targets as $materialId => $target) {
                $already = (float) DB::table('material_issue_lines')
                    ->join('material_issues', 'material_issues.id', '=', 'material_issue_lines.material_issue_id')
                    ->where('material_issues.production_order_id', $locked->id)
                    ->where('material_issues.mode', 'BACKFLUSH')
                    ->where('material_issue_lines.material_id', $materialId)->sum('material_issue_lines.qty');
                $remainingTarget = round($target['qty'] - $already, 4);
                if ($remainingTarget <= 0) continue;

                $reservations = StockReservation::withoutGlobalScopes()
                    ->where('company_id', $locked->company_id)->where('mo_id', $locked->id)
                    ->where('warehouse_id', $warehouseId)->where('material_id', $materialId)
                    ->whereIn('status', ['ACTIVE', 'PARTIAL_ISSUED'])->orderBy('id')->lockForUpdate()->get();
                foreach ($reservations as $reservation) {
                    if ($remainingTarget <= 0) break;
                    $qty = min($reservation->remaining(), $remainingTarget);
                    if ($qty <= 0) continue;
                    $resolved[] = compact('materialId', 'target', 'reservation', 'qty');
                    $remainingTarget = round($remainingTarget - $qty, 4);
                }
                if ($remainingTarget > 0.0001) {
                    throw new RuntimeException("BR-060: reservation backflush tidak cukup untuk material #{$materialId}.");
                }
            }
            if ($resolved === []) return null;

            $issue = MaterialIssue::create([
                'company_id' => $locked->company_id, 'doc_no' => $this->numbering->next($locked->company_id, 'MI'),
                'production_order_id' => $locked->id, 'warehouse_id' => $warehouseId,
                'mode' => 'BACKFLUSH', 'status' => 'POSTED', 'created_by' => $user->id,
            ]);
            $itsLines = [];
            foreach ($resolved as $item) {
                $reservation = $item['reservation'];
                $issueLine = $issue->lines()->create([
                    'material_id' => $item['materialId'], 'stock_reservation_id' => $reservation->id,
                    'roll_id' => $reservation->roll_id, 'lot_no' => $reservation->lot_no,
                    'qty' => $item['qty'], 'uom_id' => $item['target']['uom_id'],
                ]);
                $itsLines[] = [
                    'material_id' => $item['materialId'], 'warehouse_id' => $warehouseId,
                    'location_id' => $reservation->location_id, 'roll_id' => $reservation->roll_id,
                    'lot_no' => $reservation->lot_no, 'ownership' => $reservation->ownership,
                    'qty' => $item['qty'], 'uom_id' => $item['target']['uom_id'],
                    'source_document_line_id' => $issueLine->id,
                ];
                $this->recordIssued($reservation, $item['qty']);
                $locked->materialAllocations()->where('material_id', $item['materialId'])->increment('qty_issued', $item['qty']);
            }
            $this->its->post('MATERIAL_ISSUE', [
                'company_id' => $locked->company_id, 'source_document_type' => 'material_issues', 'source_document_id' => $issue->id,
            ], $itsLines, $user);
            $this->audit->record('create', $issue, after: ['doc_no' => $issue->doc_no, 'mode' => 'BACKFLUSH']);
            return $issue->load('lines');
        });
    }

    public function returnLeftover(ProductionOrder $mo, FabricRoll $roll, int $warehouseId, User $user, ?string $reason = null): FabricReturn
    {
        return DB::transaction(function () use ($mo, $roll, $warehouseId, $user, $reason): FabricReturn {
            $lockedMo = ProductionOrder::withoutGlobalScopes()->whereKey($mo->id)->lockForUpdate()->firstOrFail();
            $this->assertAccess($lockedMo, $warehouseId, $user);
            $lockedRoll = FabricRoll::withoutGlobalScopes()->where('company_id', $lockedMo->company_id)
                ->whereKey($roll->id)->lockForUpdate()->firstOrFail();
            $qty = (float) $lockedRoll->qty_remaining_meter;
            if ($qty <= 0 || empty($lockedRoll->material?->use_uom_id)) {
                throw new RuntimeException('Roll tidak punya sisa valid atau use UOM tidak tersedia.');
            }
            $return = FabricReturn::create([
                'company_id' => $lockedMo->company_id, 'doc_no' => $this->numbering->next($lockedMo->company_id, 'MI'),
                'production_order_id' => $lockedMo->id, 'roll_id' => $lockedRoll->id,
                'warehouse_id' => $warehouseId, 'qty_returned_meter' => $qty,
                'reason' => $reason, 'created_by' => $user->id,
            ]);
            $this->its->post('PRODUCTION_RETURN', [
                'company_id' => $lockedMo->company_id, 'source_document_type' => 'fabric_returns', 'source_document_id' => $return->id,
            ], [[
                'material_id' => $lockedRoll->material_id, 'warehouse_id' => $warehouseId,
                'roll_id' => $lockedRoll->id, 'lot_no' => $lockedRoll->lot_no,
                'qty' => $qty, 'uom_id' => $lockedRoll->material->use_uom_id,
            ]], $user);
            $lockedRoll->update(['qty_remaining_meter' => 0, 'status' => 'CONSUMED']);
            $this->audit->record('create', $return, after: ['doc_no' => $return->doc_no, 'qty' => $qty]);
            return $return;
        });
    }

    private function recordIssued(StockReservation $reservation, float $qty): void
    {
        $reservation->qty_issued = (float) $reservation->qty_issued + $qty;
        $reservation->status = $reservation->remaining() <= 0.0001 ? 'FULLY_ISSUED' : 'PARTIAL_ISSUED';
        $reservation->save();
    }

    private function assertAccess(ProductionOrder $mo, int $warehouseId, User $user): void
    {
        if ((int) $user->company_id !== (int) $mo->company_id && ! $user->companies()->whereKey($mo->company_id)->exists()) {
            throw new RuntimeException('User tidak memiliki akses ke company MO.');
        }
        if (! DB::table('warehouses')->where('id', $warehouseId)->where('company_id', $mo->company_id)->exists()) {
            throw new RuntimeException('Warehouse tidak ditemukan pada company MO.');
        }
    }
}
