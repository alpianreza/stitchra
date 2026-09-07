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
use Modules\Receiving\Models\Putaway;
use RuntimeException;

/** Initial, same-warehouse putaway; not a replacement for transfers or adjustments. */
class PutawayService
{
    public function __construct(
        private NumberingService $numbering,
        private ReceiptStockService $stock,
        private InventoryTransactionService $its,
        private AuditService $audit,
    ) {}

    public function create(int $companyId, GoodsReceipt $gr, array $lines, ?string $notes, User $user): Putaway
    {
        $this->stock->authorize($companyId, $user);
        if ((int) $gr->company_id !== $companyId) {
            throw new RuntimeException('GR berasal dari company lain.');
        }
        Validator::make(['lines' => $lines, 'notes' => $notes], [
            'lines' => 'required|array|min:1|max:200', 'lines.*.gr_line_id' => 'required|integer|min:1',
            'lines.*.roll_id' => 'nullable|integer|min:1', 'lines.*.to_location_id' => 'required|integer|min:1',
            'lines.*.qty' => 'required|numeric|min:0.0001', 'notes' => 'nullable|string|max:5000',
        ])->validate();

        return DB::transaction(function () use ($companyId, $gr, $lines, $notes, $user): Putaway {
            $gr = $this->stock->lockGr($companyId, (int) $gr->id, $user);
            $putaway = Putaway::create([
                'company_id' => $companyId, 'doc_no' => $this->numbering->next($companyId, 'PUT'),
                'goods_receipt_id' => $gr->id, 'notes' => $notes, 'status' => 'DRAFT', 'created_by' => $user->id,
            ]);
            $seen = [];
            foreach ($lines as $input) {
                [, , $receipt] = $this->stock->resolve($gr, (int) $input['gr_line_id'], ! empty($input['roll_id']) ? (int) $input['roll_id'] : null);
                $qty = round((float) $input['qty'], 4);
                $key = $receipt->id.':'.$input['to_location_id'];
                if (isset($seen[$key])) {
                    throw new RuntimeException('Putaway unit/destination duplikat.');
                }
                $seen[$key] = true;
                $this->assertEligible($receipt, (int) $input['to_location_id'], $qty);
                if (round($this->stock->allocatedPutawayQty((int) $receipt->id) + $qty, 4) > (float) $receipt->qty_in) {
                    throw new RuntimeException('Qty melebihi sisa receipt yang belum dialokasikan putaway.');
                }
                $putaway->lines()->create([
                    'receipt_ledger_id' => $receipt->id, 'gr_line_id' => $receipt->source_document_line_id,
                    'roll_id' => $receipt->roll_id, 'from_location_id' => $receipt->location_id,
                    'to_location_id' => $input['to_location_id'], 'qty' => $qty, 'uom_id' => $receipt->uom_id,
                ]);
            }
            $this->audit->record('create', $putaway, after: $putaway->load('lines')->toArray());

            return $putaway;
        }, 3);
    }

    public function post(Putaway $putaway, User $user): Putaway
    {
        $this->stock->authorize((int) $putaway->company_id, $user);

        return DB::transaction(function () use ($putaway, $user): Putaway {
            $gr = $this->stock->lockGr((int) $putaway->company_id, (int) $putaway->goods_receipt_id, $user);
            $locked = Putaway::withoutGlobalScopes()->where('company_id', $gr->company_id)
                ->where('goods_receipt_id', $gr->id)->whereKey($putaway->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'POSTED') {
                return $locked->load('lines');
            }
            if ($locked->status !== 'DRAFT') {
                throw new RuntimeException('Hanya putaway DRAFT yang dapat diposting.');
            }
            $lines = $locked->lines()->orderBy('receipt_ledger_id')->orderBy('id')->lockForUpdate()->get();
            if ($lines->isEmpty()) {
                throw new RuntimeException('Putaway wajib memiliki line.');
            }
            $quantities = $lines->groupBy('receipt_ledger_id')->map(fn ($group) => round((float) $group->sum('qty'), 4));
            $out = [];
            $in = [];
            foreach ($lines as $line) {
                [, , $receipt] = $this->stock->resolve($gr, (int) $line->gr_line_id, $line->roll_id ? (int) $line->roll_id : null);
                $this->assertEligible($receipt, (int) $line->to_location_id, (float) $line->qty);
                if ((int) $line->receipt_ledger_id !== (int) $receipt->id || $line->from_location_id !== $receipt->location_id
                    || (int) $line->uom_id !== (int) $receipt->uom_id) {
                    throw new RuntimeException('Snapshot putaway berbeda dari authority receipt.');
                }
                if (round($this->stock->allocatedPutawayQty((int) $receipt->id, (int) $locked->id) + $quantities[$receipt->id], 4) > (float) $receipt->qty_in) {
                    throw new RuntimeException('Alokasi putaway melebihi receipt.');
                }
                $balance = $this->stock->lockSourceBalance($receipt);
                if ($balance->avg_cost === null || (float) $balance->avg_cost < 0) {
                    throw new RuntimeException('Valuation source putaway belum tersedia.');
                }
                // Snapshot moving-average at source, not the historical PO price.
                $line->update(['unit_cost' => $balance->avg_cost]);
                $payload = $this->stock->stockLine($receipt, (float) $line->qty, (int) $line->id);
                $payload['unit_cost'] = $line->unit_cost;
                $out[] = $payload;
                $payload['location_id'] = $line->to_location_id;
                $in[] = $payload;
            }
            $header = ['company_id' => $gr->company_id, 'source_document_type' => 'putaways', 'source_document_id' => $locked->id];
            $outbound = $this->its->post('TRANSFER_OUT', $header, $out, $user);
            $inbound = $this->its->post('TRANSFER_IN', $header, $in, $user);
            $locked->update(['status' => 'POSTED', 'posted_at' => now(), 'updated_by' => $user->id]);
            $this->audit->record('post', $locked, after: ['outbound_id' => $outbound->id, 'inbound_id' => $inbound->id, 'lines' => $locked->lines->toArray()]);

            return $locked->load('lines');
        }, 3);
    }

    public function cancel(Putaway $putaway, User $user): Putaway
    {
        $this->stock->authorize((int) $putaway->company_id, $user);

        return DB::transaction(function () use ($putaway, $user): Putaway {
            $this->stock->lockGr((int) $putaway->company_id, (int) $putaway->goods_receipt_id, $user);
            $locked = Putaway::withoutGlobalScopes()->where('company_id', $putaway->company_id)->whereKey($putaway->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'CANCELLED') {
                return $locked;
            }
            if ($locked->status !== 'DRAFT' || $locked->posted_at) {
                throw new RuntimeException('Putaway yang sudah diposting tidak dapat dibatalkan.');
            }
            $locked->update(['status' => 'CANCELLED', 'updated_by' => $user->id]);
            $this->audit->record('cancel', $locked, after: ['status' => 'CANCELLED']);

            return $locked;
        });
    }

    private function assertEligible(StockLedger $receipt, int $locationId, float $qty): void
    {
        if ($this->stock->qcResult($receipt) !== 'PASS') {
            throw new RuntimeException('Putaway hanya untuk unit dengan finalized Inward QC PASS.');
        }
        if ($this->stock->returnedQty($receipt) > 0 || $this->stock->hasActiveReturn((int) $receipt->id)) {
            throw new RuntimeException('Unit memiliki Supplier Return; putaway ditolak.');
        }
        if ($qty <= 0 || ($receipt->roll_id && round($qty, 4) !== (float) $receipt->qty_in)) {
            throw new RuntimeException('Putaway fabric harus satu roll utuh; partial qty hanya untuk material non-roll.');
        }
        if ($locationId === (int) $receipt->location_id) {
            throw new RuntimeException('Lokasi putaway harus berbeda dari lokasi receipt.');
        }
        $valid = DB::table('locations as l')->join('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->where('l.id', $locationId)->where('w.id', $receipt->warehouse_id)
            ->where('w.company_id', $receipt->company_id)->where('w.is_active', true)->whereNull('w.deleted_at')->exists();
        if (! $valid) {
            throw new RuntimeException('Lokasi tujuan harus berada pada warehouse receipt yang aktif.');
        }
    }
}
