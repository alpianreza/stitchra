<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Approval\ApprovalEngine;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockLedger;
use Modules\Inventory\Models\StockOpname;
use Modules\Inventory\Models\StockTransfer;
use RuntimeException;

class InventoryOpsService
{
    public function __construct(
        private NumberingService $numbering,
        private InventoryTransactionService $its,
        private ApprovalEngine $approval,
        private AuditService $audit,
    ) {}

    public function createTransfer(int $companyId, array $header, array $lines, User $user): StockTransfer
    {
        if ((int) $header['from_warehouse_id'] === (int) $header['to_warehouse_id']) {
            throw new RuntimeException('Gudang asal dan tujuan tidak boleh sama.');
        }
        if ($lines === []) {
            throw new RuntimeException('Transfer wajib punya minimal 1 line.');
        }
        $this->assertUserCompany($user, $companyId);
        $this->assertCompanyReference('warehouses', (int) $header['from_warehouse_id'], $companyId, 'Gudang asal');
        $this->assertCompanyReference('warehouses', (int) $header['to_warehouse_id'], $companyId, 'Gudang tujuan');

        $seen = [];
        foreach ($lines as $line) {
            $this->assertInventoryLine($companyId, $line);
            if ((float) ($line['qty'] ?? 0) <= 0) {
                throw new RuntimeException('Qty transfer wajib lebih besar dari nol.');
            }
            $key = implode(':', [(int) $line['material_id'], $line['lot_no'] ?? '', $line['roll_id'] ?? '', (int) $line['uom_id']]);
            if (isset($seen[$key])) {
                throw new RuntimeException('Line transfer duplikat.');
            }
            $seen[$key] = true;
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

    public function postTransfer(StockTransfer $transfer, User $user): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $user): StockTransfer {
            $locked = StockTransfer::withoutGlobalScopes()->with('lines')->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            $this->assertUserCompany($user, (int) $locked->company_id);
            if ($locked->status !== 'DRAFT') {
                throw new RuntimeException('Hanya transfer DRAFT yang bisa di-post.');
            }

            $lines = $locked->lines->map(function ($line) use ($locked): array {
                $balance = StockBalance::withoutGlobalScopes()
                    ->where('company_id', $locked->company_id)
                    ->where('material_id', $line->material_id)
                    ->where('warehouse_id', $locked->from_warehouse_id)
                    ->where('lot_no', $line->lot_no)
                    ->where('roll_id', $line->roll_id)
                    ->where('ownership', 'COMPANY')
                    ->first();

                return [
                    'material_id' => $line->material_id,
                    'warehouse_id' => $locked->from_warehouse_id,
                    'lot_no' => $line->lot_no,
                    'roll_id' => $line->roll_id,
                    'qty' => (float) $line->qty,
                    'uom_id' => $line->uom_id,
                    'unit_cost' => $balance?->avg_cost,
                    'source_document_line_id' => $line->id,
                ];
            })->all();

            $this->its->post('TRANSFER_OUT', [
                'company_id' => $locked->company_id,
                'source_document_type' => 'stock_transfers',
                'source_document_id' => $locked->id,
            ], $lines, $user);

            $locked->update(['status' => 'IN_TRANSIT', 'updated_by' => $user->id]);
            $this->audit->record('update', $locked, after: ['status' => 'IN_TRANSIT']);
            return $locked->fresh();
        });
    }

    public function receiveTransfer(StockTransfer $transfer, User $user): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $user): StockTransfer {
            $locked = StockTransfer::withoutGlobalScopes()->with('lines')->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            $this->assertUserCompany($user, (int) $locked->company_id);
            if ($locked->status !== 'IN_TRANSIT') {
                throw new RuntimeException('Hanya transfer IN_TRANSIT yang bisa diterima.');
            }

            $lines = $locked->lines->map(function ($line) use ($locked): array {
                $outbound = StockLedger::withoutGlobalScopes()
                    ->where('company_id', $locked->company_id)
                    ->where('movement_type', 'TRANSFER_OUT')
                    ->where('source_document_type', 'stock_transfers')
                    ->where('source_document_id', $locked->id)
                    ->where('source_document_line_id', $line->id)
                    ->firstOrFail();

                return [
                    'material_id' => $line->material_id,
                    'warehouse_id' => $locked->to_warehouse_id,
                    'lot_no' => $line->lot_no,
                    'roll_id' => $line->roll_id,
                    'qty' => (float) $line->qty,
                    'uom_id' => $line->uom_id,
                    'unit_cost' => $outbound->unit_cost,
                    'source_document_line_id' => $line->id,
                ];
            })->all();

            $this->its->post('TRANSFER_IN', [
                'company_id' => $locked->company_id,
                'source_document_type' => 'stock_transfers',
                'source_document_id' => $locked->id,
            ], $lines, $user);

            $locked->update(['status' => 'RECEIVED', 'updated_by' => $user->id]);
            $this->audit->record('update', $locked, after: ['status' => 'RECEIVED']);
            return $locked->fresh();
        });
    }

    public function createAdjustment(int $companyId, string $reason, array $lines, User $user): StockAdjustment
    {
        if ($lines === []) {
            throw new RuntimeException('Adjustment wajib punya minimal 1 line.');
        }
        $this->assertUserCompany($user, $companyId);
        foreach ($lines as $line) {
            $this->assertInventoryLine($companyId, $line);
            if ((float) ($line['qty_delta'] ?? 0) === 0.0) {
                throw new RuntimeException('qty_delta tidak boleh 0.');
            }
            if ((float) $line['qty_delta'] > 0 && ! isset($line['unit_cost'])) {
                throw new RuntimeException('Penambahan stok wajib menyertakan unit_cost.');
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
            foreach ($lines as $line) $adj->lines()->create($line);
            return $adj->load('lines');
        });
    }

    public function submitAdjustment(StockAdjustment $adj, User $user): void
    {
        DB::transaction(function () use ($adj, $user): void {
            $locked = StockAdjustment::withoutGlobalScopes()->whereKey($adj->id)->lockForUpdate()->firstOrFail();
            $this->assertUserCompany($user, (int) $locked->company_id);
            if ($locked->status !== 'DRAFT') {
                throw new RuntimeException('Hanya adjustment DRAFT yang bisa disubmit.');
            }
            $locked->update(['status' => 'SUBMITTED', 'updated_by' => $user->id]);
            $this->approval->submit($locked, 'ADJ', $user);
        });
    }

    public function applyAdjustmentOnApproval(int $adjustmentId): void
    {
        DB::transaction(function () use ($adjustmentId): void {
            $adj = StockAdjustment::withoutGlobalScopes()->with('lines')->whereKey($adjustmentId)->lockForUpdate()->firstOrFail();
            $alreadyApplied = StockLedger::withoutGlobalScopes()
                ->where('source_document_type', 'stock_adjustments')
                ->where('source_document_id', $adj->id)
                ->exists();
            if ($alreadyApplied) {
                if ($adj->status !== 'APPROVED') $adj->update(['status' => 'APPROVED']);
                return;
            }
            if ($adj->status !== 'SUBMITTED') {
                throw new RuntimeException('Adjustment harus SUBMITTED sebelum diterapkan.');
            }

            $user = User::withoutGlobalScopes()->findOrFail($adj->created_by);
            foreach ($adj->lines as $line) {
                $this->its->adjust($adj->company_id, [
                    'material_id' => $line->material_id, 'warehouse_id' => $line->warehouse_id,
                    'location_id' => $line->location_id, 'lot_no' => $line->lot_no,
                    'roll_id' => $line->roll_id, 'uom_id' => $line->uom_id,
                    'unit_cost' => $line->unit_cost, 'source_document_line_id' => $line->id,
                ], (float) $line->qty_delta, 'stock_adjustments', $adj->id, $user);
            }
            $adj->update(['status' => 'APPROVED']);
        });
    }

    public function createOpname(int $companyId, int $warehouseId, User $user): StockOpname
    {
        $this->assertUserCompany($user, $companyId);
        $this->assertCompanyReference('warehouses', $warehouseId, $companyId, 'Warehouse');

        return DB::transaction(function () use ($companyId, $warehouseId, $user): StockOpname {
            $opname = StockOpname::create([
                'company_id' => $companyId,
                'doc_no' => $this->numbering->next($companyId, 'OPN'),
                'warehouse_id' => $warehouseId,
                'opname_date' => now()->toDateString(),
                'status' => 'COUNTING',
                'created_by' => $user->id,
            ]);
            $balances = StockBalance::withoutGlobalScopes()
                ->where('company_id', $companyId)->where('warehouse_id', $warehouseId)
                ->where('on_hand', '>', 0)->lockForUpdate()->get();
            foreach ($balances as $balance) {
                $opname->lines()->create([
                    'material_id' => $balance->material_id, 'location_id' => $balance->location_id,
                    'lot_no' => $balance->lot_no, 'roll_id' => $balance->roll_id,
                    'system_qty' => $balance->on_hand,
                ]);
            }
            $this->audit->record('create', $opname, after: ['doc_no' => $opname->doc_no, 'lines' => $balances->count()]);
            return $opname->load('lines');
        });
    }

    public function recordCountsAndSubmit(StockOpname $opname, array $counts, User $user): StockOpname
    {
        return DB::transaction(function () use ($opname, $counts, $user): StockOpname {
            $locked = StockOpname::withoutGlobalScopes()->with('lines')->whereKey($opname->id)->lockForUpdate()->firstOrFail();
            $this->assertUserCompany($user, (int) $locked->company_id);
            if ($locked->status !== 'COUNTING') {
                throw new RuntimeException('Opname hanya bisa dihitung saat status COUNTING.');
            }

            $byId = collect($counts)->keyBy('line_id');
            if ($byId->count() !== count($counts) || $byId->count() !== $locked->lines->count()) {
                throw new RuntimeException('Seluruh line opname wajib dihitung tepat satu kali.');
            }
            foreach ($locked->lines as $line) {
                $count = $byId->get($line->id);
                if ($count === null || (float) $count['counted_qty'] < 0) {
                    throw new RuntimeException('Count opname tidak valid.');
                }
                $line->update([
                    'counted_qty' => (float) $count['counted_qty'],
                    'variance_qty' => (float) $count['counted_qty'] - (float) $line->system_qty,
                ]);
            }

            $locked->update(['status' => 'SUBMITTED', 'updated_by' => $user->id]);
            $this->approval->submit($locked, 'OPN', $user);
            return $locked->fresh('lines');
        });
    }

    public function applyOpnameOnApproval(int $opnameId): void
    {
        DB::transaction(function () use ($opnameId): void {
            $opname = StockOpname::withoutGlobalScopes()->with('lines')->whereKey($opnameId)->lockForUpdate()->firstOrFail();
            $alreadyApplied = StockLedger::withoutGlobalScopes()
                ->where('source_document_type', 'stock_opnames')
                ->where('source_document_id', $opname->id)
                ->exists();
            if ($alreadyApplied) {
                if ($opname->status !== 'APPROVED') $opname->update(['status' => 'APPROVED']);
                return;
            }
            if ($opname->status !== 'SUBMITTED') {
                throw new RuntimeException('Opname harus SUBMITTED sebelum diterapkan.');
            }

            $user = User::withoutGlobalScopes()->findOrFail($opname->created_by);
            foreach ($opname->lines as $line) {
                $variance = (float) $line->variance_qty;
                if ($line->counted_qty === null || abs($variance) < 0.0001) continue;

                $ledger = StockLedger::withoutGlobalScopes()
                    ->where('company_id', $opname->company_id)
                    ->where('material_id', $line->material_id)
                    ->where('warehouse_id', $opname->warehouse_id)
                    ->where('location_id', $line->location_id)
                    ->where('lot_no', $line->lot_no)
                    ->where('roll_id', $line->roll_id)
                    ->latest('id')->first();
                if ($ledger === null) {
                    throw new RuntimeException('UOM opname tidak dapat diturunkan dari ledger.');
                }

                $balance = StockBalance::withoutGlobalScopes()
                    ->where('company_id', $opname->company_id)
                    ->where('material_id', $line->material_id)
                    ->where('warehouse_id', $opname->warehouse_id)
                    ->where('location_id', $line->location_id)
                    ->where('lot_no', $line->lot_no)
                    ->where('roll_id', $line->roll_id)->first();

                $this->its->adjust($opname->company_id, [
                    'material_id' => $line->material_id, 'warehouse_id' => $opname->warehouse_id,
                    'location_id' => $line->location_id, 'lot_no' => $line->lot_no,
                    'roll_id' => $line->roll_id, 'uom_id' => $ledger->uom_id,
                    'unit_cost' => $variance > 0 ? $balance?->avg_cost : null,
                    'source_document_line_id' => $line->id,
                ], $variance, 'stock_opnames', $opname->id, $user);
            }
            $opname->update(['status' => 'APPROVED']);
        });
    }

    private function assertInventoryLine(int $companyId, array $line): void
    {
        $this->assertCompanyReference('materials', (int) ($line['material_id'] ?? 0), $companyId, 'Material');
        $this->assertCompanyReference('uoms', (int) ($line['uom_id'] ?? 0), $companyId, 'UOM');
        if (! empty($line['warehouse_id'])) {
            $this->assertCompanyReference('warehouses', (int) $line['warehouse_id'], $companyId, 'Warehouse');
        }
        if (! empty($line['roll_id']) && ! DB::table('fabric_rolls')->where('id', $line['roll_id'])->where('company_id', $companyId)->exists()) {
            throw new RuntimeException('Roll tidak ditemukan pada company aktif.');
        }
    }

    private function assertCompanyReference(string $table, int $id, int $companyId, string $label): void
    {
        if ($id <= 0 || ! DB::table($table)->where('id', $id)->where('company_id', $companyId)->exists()) {
            throw new RuntimeException("{$label} tidak ditemukan pada company aktif.");
        }
    }

    private function assertUserCompany(User $user, int $companyId): void
    {
        if ((int) $user->company_id !== $companyId && ! $user->companies()->whereKey($companyId)->exists()) {
            throw new RuntimeException('User tidak memiliki akses ke company dokumen.');
        }
    }
}
