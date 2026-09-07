<?php

namespace Modules\Receiving\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Support\CurrentCompany;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockLedger;
use Modules\Receiving\Models\FabricRoll;
use Modules\Receiving\Models\GoodsReceipt;
use Modules\Receiving\Models\GrLine;
use RuntimeException;

/** Resolve stock dimensions from the immutable PURCHASE_RECEIPT, including legacy GRs. */
class ReceiptStockService
{
    public function authorize(int $companyId, User $user): void
    {
        if ((CurrentCompany::id() !== null && CurrentCompany::id() !== $companyId)
            || ((int) $user->company_id !== $companyId && ! $user->companies()->whereKey($companyId)->exists())) {
            throw new RuntimeException('User tidak memiliki akses ke company receiving.');
        }
    }

    public function lockGr(int $companyId, int $id, User $user): GoodsReceipt
    {
        $this->authorize($companyId, $user);
        $gr = GoodsReceipt::withoutGlobalScopes()->where('company_id', $companyId)->whereKey($id)->lockForUpdate()->first();
        if (! $gr || $gr->status !== 'POSTED') {
            throw new RuntimeException('GR POSTED tidak ditemukan pada company aktif.');
        }

        return $gr;
    }

    /** @return array{0: GrLine, 1: ?FabricRoll, 2: StockLedger} */
    public function resolve(GoodsReceipt $gr, int $grLineId, ?int $rollId, bool $lock = true): array
    {
        $query = $gr->lines()->whereKey($grLineId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $line = $query->first();
        if (! $line) {
            throw new RuntimeException('Line bukan milik GR ini.');
        }
        $roll = null;
        if ($line->rolls()->withoutGlobalScopes()->exists()) {
            $query = $line->rolls()->withoutGlobalScopes()->where('company_id', $gr->company_id)->whereKey($rollId ?? 0);
            if ($lock) {
                $query->lockForUpdate();
            }
            $roll = $query->first();
            if (! $roll) {
                throw new RuntimeException('Fabric wajib menunjuk roll yang benar; seluruh line fabric tidak boleh diretur sekaligus.');
            }
        } elseif ($rollId !== null) {
            throw new RuntimeException('Roll tidak valid untuk GR line ini.');
        }
        $receipts = StockLedger::withoutGlobalScopes()->where('company_id', $gr->company_id)
            ->where('movement_type', 'PURCHASE_RECEIPT')->where('source_document_type', 'goods_receipts')
            ->where('source_document_id', $gr->id)->where('source_document_line_id', $line->id)
            ->where('roll_id', $roll?->id)->get();
        if ($receipts->count() !== 1) {
            throw new RuntimeException('Authority receipt ledger tidak lengkap/ambigu; dimensi stok tidak boleh ditebak.');
        }
        $receipt = $receipts->first();
        if ($receipt->item_type !== 'MATERIAL' || (int) $receipt->material_id !== (int) $line->material_id
            || (int) $receipt->warehouse_id !== (int) $gr->warehouse_id || (float) $receipt->qty_in <= 0) {
            throw new RuntimeException('Receipt ledger tidak cocok dengan GR/material/warehouse.');
        }

        return [$line, $roll, $receipt];
    }

    public function qcResult(StockLedger $receipt): ?string
    {
        $results = DB::table('inward_inspection_lines as il')->join('inward_inspections as i', 'i.id', '=', 'il.inward_inspection_id')
            ->where('i.company_id', $receipt->company_id)->where('i.goods_receipt_id', $receipt->source_document_id)
            ->whereNotNull('i.finalized_at')->where('il.gr_line_id', $receipt->source_document_line_id)
            ->where('il.roll_id', $receipt->roll_id)->pluck('il.result');
        if ($results->count() > 1) {
            throw new RuntimeException('Lebih dari satu finalized QC untuk unit receipt; perlu rekonsiliasi, bukan release/return ulang.');
        }

        return $results->first();
    }

    public function returnedQty(StockLedger $receipt): float
    {
        // Historical return ledger points to gr_line_id; new ledger points to normalized return_line_id.
        return (float) DB::table('stock_ledger as sl')
            ->join('supplier_returns as sr', 'sr.id', '=', 'sl.source_document_id')
            ->where('sl.company_id', $receipt->company_id)->where('sr.company_id', $receipt->company_id)
            ->where('sr.goods_receipt_id', $receipt->source_document_id)
            ->where('sl.source_document_type', 'supplier_returns')->where('sl.movement_type', 'PURCHASE_RETURN')
            ->where('sl.material_id', $receipt->material_id)->where('sl.roll_id', $receipt->roll_id)
            ->where(function ($query) use ($receipt) {
                $query->whereExists(function ($lines) use ($receipt) {
                    $lines->selectRaw('1')->from('supplier_return_lines as srl')
                        ->whereColumn('srl.supplier_return_id', 'sr.id')->whereColumn('srl.id', 'sl.source_document_line_id')
                        ->where('srl.receipt_ledger_id', $receipt->id);
                })->orWhere(function ($legacy) use ($receipt) {
                    $legacy->where('sl.source_document_line_id', $receipt->source_document_line_id)
                        ->whereNotExists(fn ($lines) => $lines->selectRaw('1')->from('supplier_return_lines as old_line')
                            ->whereColumn('old_line.supplier_return_id', 'sr.id'));
                });
            })->sum('sl.qty_out');
    }

    public function hasActiveReturn(int $receiptId, ?int $exceptId = null): bool
    {
        $query = DB::table('supplier_return_lines as l')->join('supplier_returns as r', 'r.id', '=', 'l.supplier_return_id')
            ->where('l.receipt_ledger_id', $receiptId)->where('r.status', '<>', 'CANCELLED');
        if ($exceptId !== null) {
            $query->where('r.id', '<>', $exceptId);
        }

        return $query->exists();
    }

    public function allocatedPutawayQty(int $receiptId, ?int $exceptId = null): float
    {
        $query = DB::table('putaway_lines as l')->join('putaways as p', 'p.id', '=', 'l.putaway_id')
            ->where('l.receipt_ledger_id', $receiptId)->where('p.status', '<>', 'CANCELLED');
        if ($exceptId !== null) {
            $query->where('p.id', '<>', $exceptId);
        }

        return (float) $query->sum('l.qty');
    }

    public function stockLine(StockLedger $receipt, float $qty, int $sourceLineId): array
    {
        return [
            'item_type' => $receipt->item_type, 'material_id' => $receipt->material_id,
            'warehouse_id' => $receipt->warehouse_id, 'location_id' => $receipt->location_id,
            'lot_no' => $receipt->lot_no, 'roll_id' => $receipt->roll_id, 'ownership' => $receipt->ownership,
            'uom_id' => $receipt->uom_id, 'qty' => $qty, 'unit_cost' => $receipt->unit_cost,
            'source_document_line_id' => $sourceLineId,
        ];
    }

    public function lockSourceBalance(StockLedger $receipt): StockBalance
    {
        $key = [
            'company_id' => (int) $receipt->company_id, 'item_type' => $receipt->item_type,
            'material_id' => $receipt->material_id, 'style_id' => null, 'colorway_id' => null, 'size_id' => null,
            'warehouse_id' => $receipt->warehouse_id, 'location_id' => $receipt->location_id,
            'lot_no' => $receipt->lot_no, 'roll_id' => $receipt->roll_id, 'ownership' => $receipt->ownership,
        ];
        // Match ITS's dimension-lock -> balance-row order while taking a valuation snapshot.
        $normalized = $key;
        ksort($normalized);
        $balanceKey = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
        DB::table('stock_balance_locks')->insertOrIgnore(['balance_key' => $balanceKey, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('stock_balance_locks')->where('balance_key', $balanceKey)->lockForUpdate()->first();
        $balance = StockBalance::withoutGlobalScopes()->where($key)->lockForUpdate()->first();
        if (! $balance) {
            throw new RuntimeException('Saldo pada lokasi/lot receipt tidak ditemukan.');
        }

        return $balance;
    }
}
