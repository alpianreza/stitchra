<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Approval\ApprovalEngine;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\StockOpname;
use Modules\Inventory\Models\StockTransfer;
use RuntimeException;

/**
 * Operasi inventory: transfer antar gudang, adjustment (BR-017: approval-gated),
 * opname (freeze → count → variance → approval → OPNAME_ADJUSTMENT).
 * Semua efek stok via ITS (BR-013) — service ini tidak menyentuh saldo langsung.
 */
class InventoryOpsService
{
    public function __construct(
        private NumberingService $numbering,
        private InventoryTransactionService $its,
        private ApprovalEngine $approval,
        private AuditService $audit,
    ) {}

    // ─── TRANSFER ────────────────────────────────────────────────────────────

    public function createTransfer(int $companyId, array $header, array $lines, User $user): StockTransfer
    {
        if ($header['from_warehouse_id'] === $header['to_warehouse_id']) {
            throw new RuntimeException('Gudang asal dan tujuan tidak boleh sama.');
        }
        if (empty($lines)) {
            throw new RuntimeException('Transfer wajib punya minimal 1 line.');
        }

        return DB::transaction(function () use ($companyId, $header, $lines, $user): StockTransfer {
            $transfer = StockTransfer::create([
                'company_id' => $companyId,
                'doc_no' => $this->numbering->next($companyId, 'TRF'),
                'from_warehouse_id' => $header['from_warehouse_id'],
                'to_warehouse_id' => $header['to_warehouse_id'],
                'notes' => $header['notes'] ?? null,
                'status' => 'DRAFT',
                'created_by' => $user->id,
            ]);

            foreach ($lines as $line) {
                $transfer->lines()->create($line);
            }

            return $transfer->load('lines');
        });
    }

    /** Posting keluar dari gudang asal (IN_TRANSIT) */
    public function postTransfer(StockTransfer $transfer, User $user): StockTransfer
    {
        if ($transfer->status !== 'DRAFT') {
            throw new RuntimeException('Hanya transfer DRAFT yang bisa di-post.');
        }

        return DB::transaction(function () use ($transfer, $user): StockTransfer {
            $this->its->post('TRANSFER_OUT', [
                'company_id' => $transfer->company_id,
                'source_document_type' => 'stock_transfers',
                'source_document_id' => $transfer->id,
            ], $transfer->lines->map(fn ($l) => [
                'material_id' => $l->material_id,
                'warehouse_id' => $transfer->from_warehouse_id,
                'lot_no' => $l->lot_no, 'roll_id' => $l->roll_id,
                'qty' => (float) $l->qty, 'uom_id' => $l->uom_id,
                'source_document_line_id' => $l->id,
            ])->all(), $user);

            $transfer->update(['status' => 'IN_TRANSIT', 'updated_by' => $user->id]);
            $this->audit->record('update', $transfer, after: ['status' => 'IN_TRANSIT']);

            return $transfer->fresh();
        });
    }

    /** Terima di gudang tujuan (RECEIVED) */
    public function receiveTransfer(StockTransfer $transfer, User $user): StockTransfer
    {
        if ($transfer->status !== 'IN_TRANSIT') {
            throw new RuntimeException('Hanya transfer IN_TRANSIT yang bisa diterima.');
        }

        return DB::transaction(function () use ($transfer, $user): StockTransfer {
            $this->its->post('TRANSFER_IN', [
                'company_id' => $transfer->company_id,
                'source_document_type' => 'stock_transfers',
                'source_document_id' => $transfer->id,
            ], $transfer->lines->map(fn ($l) => [
                'material_id' => $l->material_id,
                'warehouse_id' => $transfer->to_warehouse_id,
                'lot_no' => $l->lot_no, 'roll_id' => $l->roll_id,
                'qty' => (float) $l->qty, 'uom_id' => $l->uom_id,
                'source_document_line_id' => $l->id,
            ])->all(), $user);

            $transfer->update(['status' => 'RECEIVED', 'updated_by' => $user->id]);
            $this->audit->record('update', $transfer, after: ['status' => 'RECEIVED']);

            return $transfer->fresh();
        });
    }

    // ─── ADJUSTMENT (BR-017: approval-gated) ─────────────────────────────────

    public function createAdjustment(int $companyId, string $reason, array $lines, User $user): StockAdjustment
    {
        if (empty($lines)) {
            throw new RuntimeException('Adjustment wajib punya minimal 1 line.');
        }
        foreach ($lines as $line) {
            if ((float) $line['qty_delta'] === 0.0) {
                throw new RuntimeException('qty_delta tidak boleh 0.');
            }
            if ((float) $line['qty_delta'] > 0 && ! isset($line['unit_cost'])) {
                throw new RuntimeException('Penambahan stok wajib menyertakan unit_cost (masuk moving average).');
            }
        }

        return DB::transaction(function () use ($companyId, $reason, $lines, $user): StockAdjustment {
            $adj = StockAdjustment::create([
                'company_id' => $companyId,
                'doc_no' => $this->numbering->next($companyId, 'ADJ'),
                'reason' => $reason,
                'status' => 'DRAFT',
                'created_by' => $user->id,
            ]);

            foreach ($lines as $line) {
                $adj->lines()->create($line);
            }

            return $adj->load('lines');
        });
    }

    public function submitAdjustment(StockAdjustment $adj, User $user): void
    {
        if ($adj->status !== 'DRAFT') {
            throw new RuntimeException('Hanya adjustment DRAFT yang bisa disubmit.');
        }

        $adj->update(['status' => 'SUBMITTED']);
        $this->approval->submit($adj, 'ADJ', $user);   // flow doc_type ADJ
    }

    /** Dipanggil listener saat approval ADJ → APPROVED: efekkan ke stok via ITS (idempotent). */
    public function applyAdjustmentOnApproval(int $adjustmentId): void
    {
        $adj = StockAdjustment::withoutGlobalScopes()->with('lines')->findOrFail($adjustmentId);

        // Idempotent: sudah pernah di-apply → skip
        $alreadyApplied = StockMovement::withoutGlobalScopes()
            ->where('source_document_type', 'stock_adjustments')
            ->where('source_document_id', $adj->id)
            ->exists();
        if ($alreadyApplied) {
            return;
        }

        $user = User::withoutGlobalScopes()->findOrFail($adj->created_by);

        DB::transaction(function () use ($adj, $user): void {
            foreach ($adj->lines as $line) {
                $this->its->adjust($adj->company_id, [
                    'material_id' => $line->material_id,
                    'warehouse_id' => $line->warehouse_id,
                    'location_id' => $line->location_id,
                    'lot_no' => $line->lot_no,
                    'roll_id' => $line->roll_id,
                    'uom_id' => $line->uom_id,
                    'unit_cost' => $line->unit_cost,
                    'source_document_line_id' => $line->id,
                ], (float) $line->qty_delta, 'stock_adjustments', $adj->id, $user);
            }
        });
    }

    // ─── OPNAME (PF-12) ──────────────────────────────────────────────────────

    /** Buat opname: freeze saldo sistem per material di gudang (system_qty snapshot). */
    public function createOpname(int $companyId, int $warehouseId, User $user): StockOpname
    {
        return DB::transaction(function () use ($companyId, $warehouseId, $user): StockOpname {
            $opname = StockOpname::create([
                'company_id' => $companyId,
                'doc_no' => $this->numbering->next($companyId, 'OPN'),
                'warehouse_id' => $warehouseId,
                'opname_date' => now()->toDateString(),
                'status' => 'COUNTING',
                'created_by' => $user->id,
            ]);

            // Freeze saldo per material (agregat lot/roll diringkas per material+location null-safe)
            $balances = StockBalance::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('warehouse_id', $warehouseId)
                ->where('on_hand', '>', 0)
                ->get();

            foreach ($balances as $b) {
                $opname->lines()->create([
                    'material_id' => $b->material_id,
                    'location_id' => $b->location_id,
                    'lot_no' => $b->lot_no,
                    'roll_id' => $b->roll_id,
                    'system_qty' => $b->on_hand,
                ]);
            }

            $this->audit->record('create', $opname, after: ['doc_no' => $opname->doc_no, 'lines' => $balances->count()]);

            return $opname->load('lines');
        });
    }

    /** Input hasil hitung fisik → variance; lalu submit untuk approval (doc_type OPN). */
    public function recordCountsAndSubmit(StockOpname $opname, array $counts, User $user): StockOpname
    {
        if ($opname->status !== 'COUNTING') {
            throw new RuntimeException('Opname hanya bisa dihitung saat status COUNTING.');
        }

        return DB::transaction(function () use ($opname, $counts, $user): StockOpname {
            foreach ($counts as $c) {
                $line = $opname->lines()->findOrFail($c['line_id']);
                $line->update([
                    'counted_qty' => (float) $c['counted_qty'],
                    'variance_qty' => (float) $c['counted_qty'] - (float) $line->system_qty,
                ]);
            }

            $opname->update(['status' => 'SUBMITTED', 'updated_by' => $user->id]);
            $this->approval->submit($opname, 'OPN', $user);

            return $opname->fresh('lines');
        });
    }

    /** Listener OPN APPROVED: variance ≠ 0 → ITS adjust (OPNAME_ADJUSTMENT), idempotent. */
    public function applyOpnameOnApproval(int $opnameId): void
    {
        $opname = StockOpname::withoutGlobalScopes()->with('lines')->findOrFail($opnameId);

        $alreadyApplied = StockMovement::withoutGlobalScopes()
            ->where('source_document_type', 'stock_opnames')
            ->where('source_document_id', $opname->id)
            ->exists();
        if ($alreadyApplied) {
            return;
        }

        $user = User::withoutGlobalScopes()->findOrFail($opname->created_by);

        DB::transaction(function () use ($opname, $user): void {
            foreach ($opname->lines as $line) {
                $variance = (float) $line->variance_qty;
                if ($line->counted_qty === null || abs($variance) < 0.0001) {
                    continue;
                }

                $this->its->adjust($opname->company_id, [
                    'material_id' => $line->material_id,
                    'warehouse_id' => $opname->warehouse_id,
                    'location_id' => $line->location_id,
                    'lot_no' => $line->lot_no,
                    'roll_id' => $line->roll_id,
                    'uom_id' => $line->material?->use_uom_id ?? 1,
                    'source_document_line_id' => $line->id,
                ], $variance, 'stock_opnames', $opname->id, $user);
            }
        });
    }
}
