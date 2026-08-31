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

class InventoryTransactionService
{
    private const INFLOW = ['OPENING', 'PURCHASE_RECEIPT', 'TRANSFER_IN', 'PRODUCTION_RETURN', 'PRODUCTION_RECEIPT', 'SUBCON_IN'];
    private const OUTFLOW = ['PURCHASE_RETURN', 'TRANSFER_OUT', 'MATERIAL_ISSUE', 'SUBCON_OUT', 'SHIPMENT'];

    public function __construct(private NumberingService $numbering, private AuditService $audit) {}

    public function post(string $movementType, array $header, array $lines, User $user): StockMovement
    {
        if (! in_array($movementType, [...self::INFLOW, ...self::OUTFLOW], true)) {
            throw new RuntimeException("ITS: movement type [{$movementType}] tidak didukung oleh post().");
        }
        if ($lines === []) {
            throw new RuntimeException('ITS: movement tanpa lines ditolak.');
        }

        $companyId = (int) ($header['company_id'] ?? 0);
        $sourceType = trim((string) ($header['source_document_type'] ?? ''));
        $sourceId = (int) ($header['source_document_id'] ?? 0);
        if ($companyId <= 0 || $sourceType === '' || $sourceId <= 0) {
            throw new RuntimeException('ITS: company dan source document wajib valid.');
        }

        return DB::transaction(function () use ($movementType, $companyId, $sourceType, $sourceId, $lines, $user): StockMovement {
            $sourceKey = [
                'company_id' => $companyId,
                'movement_type' => $movementType,
                'source_document_type' => $sourceType,
                'source_document_id' => $sourceId,
            ];

            $docNo = $this->numbering->next($companyId, $this->docTypeFor($movementType));
            $inserted = DB::table('stock_movements')->insertOrIgnore(array_merge($sourceKey, [
                'doc_no' => $docNo,
                'created_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            $movement = StockMovement::withoutGlobalScopes()->where($sourceKey)->lockForUpdate()->firstOrFail();
            if ($inserted === 0) {
                return $movement;
            }

            foreach ($lines as $line) {
                $this->postLine($movement, $movementType, $line, $user);
            }

            $this->audit->record('create', $movement, after: [
                'movement_type' => $movementType,
                'lines' => count($lines),
            ]);

            return $movement;
        });
    }

    private function postLine(StockMovement $movement, string $type, array $line, User $user): void
    {
        $qty = (float) ($line['qty'] ?? 0);
        if ($qty <= 0) {
            throw new RuntimeException('ITS: qty harus > 0.');
        }

        $isIn = in_array($type, self::INFLOW, true);
        $isOut = in_array($type, self::OUTFLOW, true);
        $balance = $this->lockBalance((int) $movement->company_id, $line);

        if ($isOut) {
            if ($type === 'PURCHASE_RETURN') {
                if ((float) $balance->quality_hold < $qty || (float) $balance->on_hand < $qty) {
                    throw new RuntimeException('ITS: quality hold tidak cukup untuk purchase return.');
                }
                $balance->quality_hold = (float) $balance->quality_hold - $qty;
            } else {
                $issuable = $balance->available();
                if ($type === 'MATERIAL_ISSUE') {
                    $issuable += min((float) $balance->reserved, $qty);
                }
                if ($issuable < $qty) {
                    throw new RuntimeException("ITS: stok tidak cukup — available {$issuable}, diminta {$qty}.");
                }
                if ($type === 'MATERIAL_ISSUE') {
                    $balance->reserved = max(0.0, (float) $balance->reserved - $qty);
                }
                if ($type === 'SUBCON_OUT') {
                    $balance->in_transit_subcon = (float) $balance->in_transit_subcon + $qty;
                }
            }
            $balance->on_hand = (float) $balance->on_hand - $qty;
        } elseif ($isIn) {
            if ($type === 'SUBCON_IN' && (float) $balance->in_transit_subcon < $qty) {
                throw new RuntimeException('ITS: qty SUBCON_IN melebihi stok in-transit.');
            }

            $oldQty = (float) $balance->on_hand;
            $balance->on_hand = $oldQty + $qty;
            if ($type === 'PURCHASE_RECEIPT') {
                $balance->quality_hold = (float) $balance->quality_hold + $qty;
            }
            if ($type === 'SUBCON_IN') {
                $balance->in_transit_subcon = (float) $balance->in_transit_subcon - $qty;
            }

            if (isset($line['unit_cost'])) {
                $unitCost = (float) $line['unit_cost'];
                if ($unitCost < 0) {
                    throw new RuntimeException('ITS: unit_cost tidak boleh negatif.');
                }
                $balance->avg_cost = round((($oldQty * (float) ($balance->avg_cost ?? 0)) + ($qty * $unitCost)) / ($oldQty + $qty), 6);
            }
        }

        $balance->save();
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

    public function releaseQualityHold(int $companyId, array $line, float $qty, User $user): void
    {
        if ($qty <= 0) {
            throw new RuntimeException('ITS: qty release harus > 0.');
        }

        DB::transaction(function () use ($companyId, $line, $qty, $user): void {
            $balance = $this->lockBalance($companyId, $line);
            if ((float) $balance->quality_hold < $qty) {
                throw new RuntimeException("ITS: quality_hold tidak cukup untuk release ({$balance->quality_hold} < {$qty}).");
            }

            $balance->quality_hold = (float) $balance->quality_hold - $qty;
            $balance->save();

            StockLedger::create([
                'company_id' => $companyId, 'movement_type' => 'QUALITY_RELEASE',
                'item_type' => $line['item_type'] ?? 'MATERIAL', 'material_id' => $line['material_id'] ?? null,
                'warehouse_id' => $line['warehouse_id'], 'location_id' => $line['location_id'] ?? null,
                'lot_no' => $line['lot_no'] ?? null, 'roll_id' => $line['roll_id'] ?? null,
                'ownership' => $line['ownership'] ?? 'COMPANY', 'qty_in' => 0, 'qty_out' => 0,
                'uom_id' => $line['uom_id'], 'running_balance' => $balance->on_hand,
                'source_document_type' => $line['source_document_type'] ?? 'inward_inspections',
                'source_document_id' => $line['source_document_id'],
                'source_document_line_id' => $line['source_document_line_id'] ?? null,
                'created_at' => now(), 'created_by' => $user->id,
            ]);
        });
    }

    public function adjust(int $companyId, array $line, float $qtyDelta, string $sourceType, int $sourceId, User $user): void
    {
        if ($qtyDelta === 0.0) {
            throw new RuntimeException('ITS: qty adjustment tidak boleh 0.');
        }

        DB::transaction(function () use ($companyId, $line, $qtyDelta, $sourceType, $sourceId, $user): void {
            $balance = $this->lockBalance($companyId, $line);
            $newOnHand = (float) $balance->on_hand + $qtyDelta;
            if ($newOnHand < 0) {
                throw new RuntimeException('ITS: adjustment membuat stok negatif — ditolak.');
            }

            if ($qtyDelta > 0 && isset($line['unit_cost'])) {
                $oldQty = (float) $balance->on_hand;
                $balance->avg_cost = round((($oldQty * (float) ($balance->avg_cost ?? 0)) + ($qtyDelta * (float) $line['unit_cost'])) / $newOnHand, 6);
            }

            $balance->on_hand = $newOnHand;
            $balance->save();

            StockLedger::create([
                'company_id' => $companyId,
                'movement_type' => $sourceType === 'stock_opnames' ? 'OPNAME_ADJUSTMENT' : 'ADJUSTMENT',
                'item_type' => $line['item_type'] ?? 'MATERIAL', 'material_id' => $line['material_id'] ?? null,
                'warehouse_id' => $line['warehouse_id'], 'location_id' => $line['location_id'] ?? null,
                'lot_no' => $line['lot_no'] ?? null, 'roll_id' => $line['roll_id'] ?? null,
                'ownership' => $line['ownership'] ?? 'COMPANY',
                'qty_in' => $qtyDelta > 0 ? $qtyDelta : 0, 'qty_out' => $qtyDelta < 0 ? abs($qtyDelta) : 0,
                'uom_id' => $line['uom_id'], 'unit_cost' => $line['unit_cost'] ?? null,
                'running_balance' => $balance->on_hand, 'source_document_type' => $sourceType,
                'source_document_id' => $sourceId, 'source_document_line_id' => $line['source_document_line_id'] ?? null,
                'created_at' => now(), 'created_by' => $user->id,
            ]);
        });
    }

    private function lockBalance(int $companyId, array $line): StockBalance
    {
        $key = [
            'company_id' => $companyId, 'item_type' => $line['item_type'] ?? 'MATERIAL',
            'material_id' => $line['material_id'] ?? null, 'style_id' => $line['style_id'] ?? null,
            'colorway_id' => $line['colorway_id'] ?? null, 'size_id' => $line['size_id'] ?? null,
            'warehouse_id' => $line['warehouse_id'], 'location_id' => $line['location_id'] ?? null,
            'lot_no' => $line['lot_no'] ?? null, 'roll_id' => $line['roll_id'] ?? null,
            'ownership' => $line['ownership'] ?? 'COMPANY',
        ];

        $normalized = $key;
        ksort($normalized);
        $balanceKey = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));

        DB::table('stock_balance_locks')->insertOrIgnore([
            'balance_key' => $balanceKey, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('stock_balance_locks')->where('balance_key', $balanceKey)->lockForUpdate()->first();

        $balance = StockBalance::withoutGlobalScopes()->where($key)->lockForUpdate()->first();
        if ($balance === null) {
            $balance = StockBalance::create($key);
            $balance = StockBalance::withoutGlobalScopes()->whereKey($balance->id)->lockForUpdate()->firstOrFail();
        }

        return $balance;
    }

    private function docTypeFor(string $movementType): string
    {
        return match ($movementType) {
            'PURCHASE_RECEIPT' => 'GR', 'MATERIAL_ISSUE' => 'MI',
            'TRANSFER_IN', 'TRANSFER_OUT' => 'TRF',
            'PRODUCTION_RETURN' => 'MI', 'PRODUCTION_RECEIPT' => 'OUT',
            'SHIPMENT' => 'SHP', 'SUBCON_OUT', 'SUBCON_IN' => 'JW',
            'PURCHASE_RETURN', 'OPENING' => 'ADJ',
            default => throw new RuntimeException("ITS: numbering tidak tersedia untuk [{$movementType}]."),
        };
    }
}
