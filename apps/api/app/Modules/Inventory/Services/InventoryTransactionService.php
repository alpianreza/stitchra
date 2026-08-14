<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockLedger;
use Modules\Inventory\Models\StockMovement;
use RuntimeException;

/**
 * BR-013: SATU-SATUNYA pintu tulis stok. Atomic: dokumen + ledger + saldo
 * dalam SATU transaksi DB — gagal satu ⇒ rollback semua (FASE 20).
 * BR-005: moving average pada penerimaan (cost tersimpan per transaksi).
 * BR-004: penerimaan masuk quality_hold; release memindah ke available.
 * BR-006: available = on_hand − reserved − quality_hold; tidak pernah negatif.
 */
class InventoryTransactionService
{
    /** Movement yang MENAMBAH stok fisik (on_hand ↑) */
    private const INFLOW = ['OPENING', 'PURCHASE_RECEIPT', 'TRANSFER_IN', 'PRODUCTION_RETURN', 'PRODUCTION_RECEIPT', 'SUBCON_IN'];
    /** Movement yang MENGURANGI stok fisik (on_hand ↓) */
    private const OUTFLOW = ['PURCHASE_RETURN', 'TRANSFER_OUT', 'MATERIAL_ISSUE', 'SUBCON_OUT', 'SHIPMENT'];

    public function __construct(
        private NumberingService $numbering,
        private AuditService $audit,
    ) {}

    /**
     * Post dokumen movement + lines.
     * $header: company_id, source_document_type, source_document_id
     * $lines[]: material_id|style variant, warehouse_id, location_id?, lot_no?, roll_id?,
     *           ownership?, qty, uom_id, unit_cost?, source_document_line_id?
     * Arah (in/out) diturunkan dari movement_type — caller TIDAK mengatur qty_in/out manual.
     */
    public function post(string $movementType, array $header, array $lines, User $user): StockMovement
    {
        if (empty($lines)) {
            throw new RuntimeException('ITS: movement tanpa lines ditolak.');
        }

        return DB::transaction(function () use ($movementType, $header, $lines, $user): StockMovement {
            $movement = StockMovement::create([
                'company_id' => $header['company_id'],
                'doc_no' => $this->numbering->next($header['company_id'], $this->docTypeFor($movementType)),
                'movement_type' => $movementType,
                'source_document_type' => $header['source_document_type'],
                'source_document_id' => $header['source_document_id'],
                'created_by' => $user->id,
            ]);

            foreach ($lines as $line) {
                $this->postLine($movement, $movementType, $line, $user);
            }

            $this->audit->record('create', $movement, after: [
                'movement_type' => $movementType, 'lines' => count($lines),
            ]);

            return $movement;
        });
    }

    /** Satu baris: ledger + saldo (locked), dalam transaksi pemanggil. */
    private function postLine(StockMovement $movement, string $type, array $line, User $user): void
    {
        $qty = (float) $line['qty'];
        if ($qty <= 0) {
            throw new RuntimeException('ITS: qty harus > 0.');
        }

        $isIn = in_array($type, self::INFLOW, true);
        $isOut = in_array($type, self::OUTFLOW, true);

        // Kunci saldo (atau buat baru terkunci) — mencegah race (FASE 20)
        $balance = $this->lockBalance($movement->company_id, $line);

        if ($isOut) {
            // Validasi available SEBELUM mengurangi (BR-006); CHECK DB sebagai lapis terakhir
            $available = $balance->available();
            if ($available < $qty) {
                throw new RuntimeException(
                    "ITS: stok tidak cukup untuk [{$line['material_id']}] — available {$available}, diminta {$qty}."
                );
            }
            $balance->on_hand = (float) $balance->on_hand - $qty;

            // Keluar dari reservasi / hold bila relevan
            if ($type === 'MATERIAL_ISSUE' && (float) $balance->reserved > 0) {
                $balance->reserved = max(0.0, (float) $balance->reserved - $qty);
            }
            if ($type === 'PURCHASE_RETURN' && (float) $balance->quality_hold > 0) {
                $balance->quality_hold = max(0.0, (float) $balance->quality_hold - $qty);
            }
            if ($type === 'SUBCON_OUT') {
                $balance->in_transit_subcon = (float) $balance->in_transit_subcon + $qty; // BR-090
            }
        } elseif ($isIn) {
            $balance->on_hand = (float) $balance->on_hand + $qty;

            if ($type === 'PURCHASE_RECEIPT') {
                $balance->quality_hold = (float) $balance->quality_hold + $qty; // BR-004
            }
            if ($type === 'SUBCON_IN') {
                $balance->in_transit_subcon = max(0.0, (float) $balance->in_transit_subcon - $qty);
            }

            // BR-005: moving average saat penerimaan berbiaya
            $unitCost = isset($line['unit_cost']) ? (float) $line['unit_cost'] : null;
            if ($unitCost !== null) {
                $oldQty = (float) $balance->on_hand - $qty;
                $oldAvg = (float) ($balance->avg_cost ?? 0);
                $balance->avg_cost = ($oldQty + $qty) > 0
                    ? round((($oldQty * $oldAvg) + ($qty * $unitCost)) / ($oldQty + $qty), 6)
                    : $unitCost;
            }
        }
        // ADJUSTMENT / OPNAME_ADJUSTMENT ditangani via qtyDelta (method khusus di bawah)

        $balance->save();

        // Ledger entry (append-only)
        StockLedger::create([
            'company_id' => $movement->company_id,
            'movement_type' => $type,
            'item_type' => $line['item_type'] ?? 'MATERIAL',
            'material_id' => $line['material_id'] ?? null,
            'style_id' => $line['style_id'] ?? null,
            'colorway_id' => $line['colorway_id'] ?? null,
            'size_id' => $line['size_id'] ?? null,
            'warehouse_id' => $line['warehouse_id'],
            'location_id' => $line['location_id'] ?? null,
            'lot_no' => $line['lot_no'] ?? null,
            'roll_id' => $line['roll_id'] ?? null,
            'ownership' => $line['ownership'] ?? 'COMPANY',
            'qty_in' => $isIn ? $qty : 0,
            'qty_out' => $isOut ? $qty : 0,
            'uom_id' => $line['uom_id'],
            'unit_cost' => $line['unit_cost'] ?? null,
            'total_cost' => isset($line['unit_cost']) ? round($qty * (float) $line['unit_cost'], 4) : null,
            'running_balance' => $balance->on_hand,
            'source_document_type' => $movement->source_document_type,
            'source_document_id' => $movement->source_document_id,
            'source_document_line_id' => $line['source_document_line_id'] ?? null,
            'created_at' => now(),
            'created_by' => $user->id,
        ]);
    }

    /** BR-004: QUALITY_HOLD → available (setelah inspeksi PASS). Bukan in/out fisik. */
    public function releaseQualityHold(int $companyId, array $line, float $qty, User $user): void
    {
        DB::transaction(function () use ($companyId, $line, $qty, $user): void {
            $balance = $this->lockBalance($companyId, $line);

            if ((float) $balance->quality_hold < $qty) {
                throw new RuntimeException("ITS: quality_hold tidak cukup untuk release ({$balance->quality_hold} < {$qty}).");
            }

            $balance->quality_hold = (float) $balance->quality_hold - $qty;
            $balance->save();

            StockLedger::create([
                'company_id' => $companyId,
                'movement_type' => 'QUALITY_RELEASE',
                'item_type' => $line['item_type'] ?? 'MATERIAL',
                'material_id' => $line['material_id'] ?? null,
                'warehouse_id' => $line['warehouse_id'],
                'location_id' => $line['location_id'] ?? null,
                'lot_no' => $line['lot_no'] ?? null,
                'roll_id' => $line['roll_id'] ?? null,
                'ownership' => $line['ownership'] ?? 'COMPANY',
                'qty_in' => 0, 'qty_out' => 0,   // pindah status, bukan fisik
                'uom_id' => $line['uom_id'],
                'running_balance' => $balance->on_hand,
                'source_document_type' => $line['source_document_type'] ?? 'inward_inspections',
                'source_document_id' => $line['source_document_id'],
                'source_document_line_id' => $line['source_document_line_id'] ?? null,
                'created_at' => now(),
                'created_by' => $user->id,
            ]);
        });
    }

    /** BR-017: adjustment ±qty (hanya dari dokumen ADJ/OPN yang APPROVED). */
    public function adjust(int $companyId, array $line, float $qtyDelta, string $sourceType, int $sourceId, User $user): void
    {
        DB::transaction(function () use ($companyId, $line, $qtyDelta, $sourceType, $sourceId, $user): void {
            $balance = $this->lockBalance($companyId, $line);

            $newOnHand = (float) $balance->on_hand + $qtyDelta;
            if ($newOnHand < 0) {
                throw new RuntimeException('ITS: adjustment membuat stok negatif — ditolak.');
            }

            // Moving average untuk penambahan berbiaya
            if ($qtyDelta > 0 && isset($line['unit_cost'])) {
                $oldQty = (float) $balance->on_hand;
                $oldAvg = (float) ($balance->avg_cost ?? 0);
                $balance->avg_cost = $newOnHand > 0
                    ? round((($oldQty * $oldAvg) + ($qtyDelta * (float) $line['unit_cost'])) / $newOnHand, 6)
                    : (float) $line['unit_cost'];
            }

            $balance->on_hand = $newOnHand;
            $balance->save();

            StockLedger::create([
                'company_id' => $companyId,
                'movement_type' => $sourceType === 'stock_opnames' ? 'OPNAME_ADJUSTMENT' : 'ADJUSTMENT',
                'item_type' => $line['item_type'] ?? 'MATERIAL',
                'material_id' => $line['material_id'] ?? null,
                'warehouse_id' => $line['warehouse_id'],
                'location_id' => $line['location_id'] ?? null,
                'lot_no' => $line['lot_no'] ?? null,
                'roll_id' => $line['roll_id'] ?? null,
                'ownership' => $line['ownership'] ?? 'COMPANY',
                'qty_in' => $qtyDelta > 0 ? $qtyDelta : 0,
                'qty_out' => $qtyDelta < 0 ? abs($qtyDelta) : 0,
                'uom_id' => $line['uom_id'],
                'unit_cost' => $line['unit_cost'] ?? null,
                'running_balance' => $balance->on_hand,
                'source_document_type' => $sourceType,
                'source_document_id' => $sourceId,
                'source_document_line_id' => $line['source_document_line_id'] ?? null,
                'created_at' => now(),
                'created_by' => $user->id,
            ]);
        });
    }

    /** Lock (atau buat+lock) baris saldo untuk kunci item. */
    private function lockBalance(int $companyId, array $line): StockBalance
    {
        $key = [
            'company_id' => $companyId,
            'item_type' => $line['item_type'] ?? 'MATERIAL',
            'material_id' => $line['material_id'] ?? null,
            'style_id' => $line['style_id'] ?? null,
            'colorway_id' => $line['colorway_id'] ?? null,
            'size_id' => $line['size_id'] ?? null,
            'warehouse_id' => $line['warehouse_id'],
            'location_id' => $line['location_id'] ?? null,
            'lot_no' => $line['lot_no'] ?? null,
            'roll_id' => $line['roll_id'] ?? null,
            'ownership' => $line['ownership'] ?? 'COMPANY',
        ];

        $balance = StockBalance::withoutGlobalScopes()->where($key)->lockForUpdate()->first();

        if ($balance === null) {
            $balance = StockBalance::create($key);   // saldo nol, lalu di-update di transaksi yang sama
            $balance = StockBalance::withoutGlobalScopes()->where('id', $balance->id)->lockForUpdate()->firstOrFail();
        }

        return $balance;
    }

    private function docTypeFor(string $movementType): string
    {
        // Prefix numbering per movement (BR-010) — config di doc_numbering_configs
        return match ($movementType) {
            'PURCHASE_RECEIPT' => 'GR',
            'MATERIAL_ISSUE' => 'MI',
            'TRANSFER_IN', 'TRANSFER_OUT' => 'WIP',
            'ADJUSTMENT' => 'ADJ',
            'OPNAME_ADJUSTMENT' => 'OPN',
            'PRODUCTION_RETURN' => 'MI',
            'PRODUCTION_RECEIPT' => 'OUT',
            'SHIPMENT' => 'SHP',
            'SUBCON_OUT', 'SUBCON_IN' => 'JW',
            default => 'ADJ',
        };
    }
}
