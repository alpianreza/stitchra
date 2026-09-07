<?php

namespace Modules\Receiving\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Inventory\Models\StockLedger;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\Receiving\Models\GoodsReceipt;
use Modules\Receiving\Models\SupplierReturn;
use RuntimeException;

class SupplierReturnService
{
    public function __construct(
        private NumberingService $numbering,
        private ReceiptStockService $stock,
        private InventoryTransactionService $its,
        private AuditService $audit,
    ) {}

    public function create(int $companyId, GoodsReceipt $gr, array $lines, string $reason, User $user): SupplierReturn
    {
        $this->stock->authorize($companyId, $user);
        if ((int) $gr->company_id !== $companyId) {
            throw new RuntimeException('GR berasal dari company lain.');
        }
        Validator::make(['lines' => $lines, 'reason' => trim($reason)], [
            'lines' => 'required|array|min:1|max:200', 'lines.*.gr_line_id' => 'required|integer|min:1',
            'lines.*.roll_id' => 'nullable|integer|min:1', 'reason' => 'required|string|max:5000',
        ])->validate();

        return DB::transaction(function () use ($companyId, $gr, $lines, $reason, $user): SupplierReturn {
            $gr = $this->stock->lockGr($companyId, (int) $gr->id, $user);
            $supplierId = DB::table('purchase_orders as po')->join('suppliers as s', 's.id', '=', 'po.supplier_id')
                ->where('po.id', $gr->purchase_order_id)->where('po.company_id', $companyId)
                ->where('s.company_id', $companyId)->value('s.id');
            if (! $supplierId) {
                throw new RuntimeException('Supplier sumber GR tidak valid pada company ini.');
            }
            $return = SupplierReturn::create([
                'company_id' => $companyId, 'doc_no' => $this->numbering->next($companyId, 'SR'),
                'goods_receipt_id' => $gr->id, 'supplier_id' => $supplierId,
                'reason' => trim($reason), 'status' => 'DRAFT', 'created_by' => $user->id,
            ]);
            foreach ($lines as $input) {
                [, , $receipt] = $this->stock->resolve($gr, (int) $input['gr_line_id'], ! empty($input['roll_id']) ? (int) $input['roll_id'] : null);
                $this->assertReturnable($receipt);
                $return->lines()->create([
                    'receipt_ledger_id' => $receipt->id, 'gr_line_id' => $receipt->source_document_line_id,
                    'roll_id' => $receipt->roll_id, 'qty' => $receipt->qty_in,
                    'uom_id' => $receipt->uom_id, 'unit_cost' => $receipt->unit_cost,
                ]);
            }
            $this->audit->record('create', $return, after: $return->load('lines')->toArray());

            return $return;
        }, 3);
    }

    public function post(SupplierReturn $return, User $user): SupplierReturn
    {
        $this->stock->authorize((int) $return->company_id, $user);

        return DB::transaction(function () use ($return, $user): SupplierReturn {
            $gr = $this->stock->lockGr((int) $return->company_id, (int) $return->goods_receipt_id, $user);
            $locked = SupplierReturn::withoutGlobalScopes()->where('company_id', $gr->company_id)
                ->where('goods_receipt_id', $gr->id)->whereKey($return->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'SHIPPED' && $locked->posted_at) {
                return $locked->load('lines');
            }
            if ($locked->status !== 'DRAFT') {
                throw new RuntimeException('Hanya Supplier Return DRAFT baru yang dapat diposting.');
            }
            $lines = $locked->lines()->orderBy('receipt_ledger_id')->lockForUpdate()->get();
            if ($lines->isEmpty()) {
                throw new RuntimeException('Return legacy tanpa normalized lines memerlukan rekonsiliasi; tidak diposting ulang.');
            }
            $out = [];
            foreach ($lines as $line) {
                [, , $receipt] = $this->stock->resolve($gr, (int) $line->gr_line_id, $line->roll_id ? (int) $line->roll_id : null);
                $this->assertReturnable($receipt, (int) $locked->id);
                if ((int) $line->receipt_ledger_id !== (int) $receipt->id || $line->qty !== $receipt->qty_in
                    || (int) $line->uom_id !== (int) $receipt->uom_id || $line->unit_cost !== $receipt->unit_cost) {
                    throw new RuntimeException('Snapshot return berbeda dari authority receipt.');
                }
                $out[] = $this->stock->stockLine($receipt, (float) $receipt->qty_in, (int) $line->id);
            }
            $movement = $this->its->post('PURCHASE_RETURN', [
                'company_id' => $gr->company_id, 'source_document_type' => 'supplier_returns', 'source_document_id' => $locked->id,
            ], $out, $user);
            foreach ($lines as $line) {
                if ($line->roll_id) {
                    $gr->lines()->findOrFail($line->gr_line_id)->rolls()->withoutGlobalScopes()->whereKey($line->roll_id)
                        ->update(['qty_remaining_use' => 0, 'qty_remaining_meter' => 0]);
                }
            }
            $locked->update(['status' => 'SHIPPED', 'posted_at' => now(), 'updated_by' => $user->id]);
            $this->audit->record('post', $locked, after: ['status' => 'SHIPPED', 'movement_id' => $movement->id, 'lines' => $out]);

            return $locked->load('lines');
        }, 3);
    }

    public function cancel(SupplierReturn $return, User $user): SupplierReturn
    {
        $this->stock->authorize((int) $return->company_id, $user);

        return DB::transaction(function () use ($return, $user): SupplierReturn {
            $this->stock->lockGr((int) $return->company_id, (int) $return->goods_receipt_id, $user);
            $locked = SupplierReturn::withoutGlobalScopes()->where('company_id', $return->company_id)->whereKey($return->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'CANCELLED') {
                return $locked;
            }
            if ($locked->status !== 'DRAFT' || $locked->posted_at) {
                throw new RuntimeException('Return yang sudah diproses tidak dapat dibatalkan.');
            }
            $locked->update(['status' => 'CANCELLED', 'updated_by' => $user->id]);
            $this->audit->record('cancel', $locked, after: ['status' => 'CANCELLED']);

            return $locked;
        });
    }

    private function assertReturnable(StockLedger $receipt, ?int $exceptId = null): void
    {
        if ($this->stock->qcResult($receipt) !== 'FAIL') {
            throw new RuntimeException('Return hanya untuk unit dengan finalized Inward QC FAIL.');
        }
        if ($this->stock->returnedQty($receipt) > 0 || $this->stock->hasActiveReturn((int) $receipt->id, $exceptId)) {
            throw new RuntimeException('Unit sudah diretur atau dialokasikan ke Supplier Return aktif.');
        }
        if ($this->stock->allocatedPutawayQty((int) $receipt->id) > 0) {
            throw new RuntimeException('Unit sudah memiliki putaway; authority QC/stock perlu direkonsiliasi.');
        }
    }
}
