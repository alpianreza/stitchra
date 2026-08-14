<?php

namespace Modules\Production\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Inventory\Models\StockReservation;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\Production\Models\FabricReturn;
use Modules\Production\Models\MaterialIssue;
use Modules\Production\Models\ProductionOrder;
use Modules\Receiving\Models\FabricRoll;
use RuntimeException;

/**
 * BR-041: fabric = issue AKTUAL per roll; trim boleh BACKFLUSH.
 * BR-060: issue mengurangi reservasi (qty_issued ↑, status PARTIAL/FULLY_ISSUED).
 * BR-013: stok hanya via ITS (atomic).
 */
class MaterialIssueService
{
    public function __construct(
        private NumberingService $numbering,
        private InventoryTransactionService $its,
        private AuditService $audit,
    ) {}

    /**
     * Issue AKTUAL dari reservasi. $lines[]: material_id, qty, uom_id,
     * roll_id? (wajib untuk fabric roll-tracked — BR-041), lot_no?
     */
    public function issue(ProductionOrder $mo, int $warehouseId, array $lines, User $user): MaterialIssue
    {
        if (! in_array($mo->status, ['RELEASED', 'CUTTING', 'SEWING'], true)) {
            throw new RuntimeException("Issue hanya untuk MO RELEASED/CUTTING/SEWING (status: {$mo->status}).");
        }
        if (empty($lines)) {
            throw new RuntimeException('Material issue wajib punya minimal 1 line.');
        }

        return DB::transaction(function () use ($mo, $warehouseId, $lines, $user): MaterialIssue {
            $issue = MaterialIssue::create([
                'company_id' => $mo->company_id,
                'doc_no' => $this->numbering->next($mo->company_id, 'MI'),
                'production_order_id' => $mo->id,
                'warehouse_id' => $warehouseId,
                'mode' => 'ACTUAL',
                'status' => 'POSTED',
                'created_by' => $user->id,
            ]);

            $itsLines = [];

            foreach ($lines as $lineData) {
                $qty = (float) $lineData['qty'];
                $reservation = StockReservation::where('mo_id', $mo->id)
                    ->where('material_id', $lineData['material_id'])
                    ->whereIn('status', ['ACTIVE', 'PARTIAL_ISSUED'])
                    ->lockForUpdate()
                    ->first();

                // BR-060: issue tidak boleh melebihi sisa reservasi
                if ($reservation === null) {
                    throw new RuntimeException("BR-060: tidak ada reservasi aktif untuk material #{$lineData['material_id']} di MO ini.");
                }
                if ($reservation->remaining() < $qty) {
                    throw new RuntimeException(
                        "BR-060: issue {$qty} melebihi sisa reservasi {$reservation->remaining()} untuk material #{$lineData['material_id']}."
                    );
                }

                // BR-041: fabric roll-tracked wajib per roll + konsumsi roll
                $material = \Modules\MasterData\Models\Material::findOrFail($lineData['material_id']);
                if ($material->isRollTracked()) {
                    if (empty($lineData['roll_id'])) {
                        throw new RuntimeException("BR-041: fabric [{$material->code}] wajib di-issue per roll (aktual).");
                    }
                    $roll = FabricRoll::lockForUpdate()->findOrFail($lineData['roll_id']);
                    if ($roll->status !== 'RELEASED') {
                        throw new RuntimeException("Roll {$roll->roll_no} berstatus {$roll->status} — tidak bisa di-issue.");
                    }
                }

                $issueLine = $issue->lines()->create([
                    'material_id' => $lineData['material_id'],
                    'stock_reservation_id' => $reservation->id,
                    'roll_id' => $lineData['roll_id'] ?? null,
                    'lot_no' => $lineData['lot_no'] ?? null,
                    'qty' => $qty,
                    'uom_id' => $lineData['uom_id'],
                ]);

                $itsLines[] = [
                    'material_id' => $lineData['material_id'],
                    'warehouse_id' => $warehouseId,
                    'roll_id' => $lineData['roll_id'] ?? null,
                    'lot_no' => $lineData['lot_no'] ?? null,
                    'qty' => $qty,
                    'uom_id' => $lineData['uom_id'],
                    'source_document_line_id' => $issueLine->id,
                ];

                // Reservasi & alokasi ter-update (BR-060)
                $reservation->qty_issued = (float) $reservation->qty_issued + $qty;
                $reservation->status = $reservation->remaining() <= 0 ? 'FULLY_ISSUED' : 'PARTIAL_ISSUED';
                $reservation->save();

                $mo->materialAllocations()->where('material_id', $lineData['material_id'])
                    ->increment('qty_issued', $qty);
            }

            // ITS: MATERIAL_ISSUE — RM ↓ (reservation juga turun di ITS), atomic (BR-013)
            $this->its->post('MATERIAL_ISSUE', [
                'company_id' => $mo->company_id,
                'source_document_type' => 'material_issues',
                'source_document_id' => $issue->id,
            ], $itsLines, $user);

            $this->audit->record('create', $issue, after: ['doc_no' => $issue->doc_no, 'lines' => count($lines)]);

            return $issue->load('lines');
        });
    }

    /**
     * BR-041: backflush trim — konsumsi standar dari BOM × qty_produced, tanpa input manual.
     */
    public function backflush(ProductionOrder $mo, int $warehouseId, User $user): ?MaterialIssue
    {
        $backflushLines = $mo->bomVersion->lines->where('is_backflush', true);

        if ($backflushLines->isEmpty()) {
            return null;
        }

        $qtyProduced = (float) $mo->qty_produced;
        if ($qtyProduced <= 0) {
            throw new RuntimeException('Backflush memerlukan qty_produced > 0 di MO.');
        }

        $lines = $backflushLines->map(fn ($bomLine) => [
            'material_id' => $bomLine->material_id,
            'qty' => round($bomLine->grossPerPcs() * $qtyProduced, 4),
            'uom_id' => $bomLine->uom_id,
        ])->all();

        return DB::transaction(function () use ($mo, $warehouseId, $lines, $user): MaterialIssue {
            $issue = MaterialIssue::create([
                'company_id' => $mo->company_id,
                'doc_no' => $this->numbering->next($mo->company_id, 'MI'),
                'production_order_id' => $mo->id,
                'warehouse_id' => $warehouseId,
                'mode' => 'BACKFLUSH',
                'status' => 'POSTED',
                'created_by' => $user->id,
            ]);

            $itsLines = [];
            foreach ($lines as $lineData) {
                $issueLine = $issue->lines()->create($lineData);
                $itsLines[] = $lineData + ['source_document_line_id' => $issueLine->id];
                $mo->materialAllocations()->where('material_id', $lineData['material_id'])
                    ->increment('qty_issued', $lineData['qty']);
            }

            $this->its->post('MATERIAL_ISSUE', [
                'company_id' => $mo->company_id,
                'source_document_type' => 'material_issues',
                'source_document_id' => $issue->id,
            ], $itsLines, $user);

            $this->audit->record('create', $issue, after: ['doc_no' => $issue->doc_no, 'mode' => 'BACKFLUSH']);

            return $issue->load('lines');
        });
    }

    /**
     * BR-042: leftover roll kembali ke RM sebagai stok available (PRODUCTION_RETURN).
     * qty = qty_remaining_meter roll (meter = UOM pakai).
     */
    public function returnLeftover(ProductionOrder $mo, FabricRoll $roll, int $warehouseId, User $user, ?string $reason = null): FabricReturn
    {
        $qty = (float) $roll->qty_remaining_meter;
        if ($qty <= 0) {
            throw new RuntimeException("Roll {$roll->roll_no} tidak punya sisa (remaining 0).");
        }

        return DB::transaction(function () use ($mo, $roll, $warehouseId, $qty, $user, $reason): FabricReturn {
            $return = FabricReturn::create([
                'company_id' => $mo->company_id,
                'doc_no' => $this->numbering->next($mo->company_id, 'MI'),
                'production_order_id' => $mo->id,
                'roll_id' => $roll->id,
                'warehouse_id' => $warehouseId,
                'qty_returned_meter' => $qty,
                'reason' => $reason,
                'created_by' => $user->id,
            ]);

            $material = $roll->material;

            // Stok kembali available di RM (dalam UOM pakai — meter)
            $this->its->post('PRODUCTION_RETURN', [
                'company_id' => $mo->company_id,
                'source_document_type' => 'fabric_returns',
                'source_document_id' => $return->id,
            ], [[
                'material_id' => $roll->material_id,
                'warehouse_id' => $warehouseId,
                'roll_id' => $roll->id,
                'lot_no' => $roll->lot_no,
                'qty' => $qty,
                'uom_id' => $material->use_uom_id,
                'unit_cost' => null,   // cost mengikuti avg_cost saldo (moving average tidak berubah)
            ]], $user);

            // Roll habis → CONSUMED
            $roll->update(['qty_remaining_meter' => 0, 'status' => 'CONSUMED']);

            $this->audit->record('create', $return, after: ['doc_no' => $return->doc_no, 'qty' => $qty]);

            return $return;
        });
    }
}
