<?php

namespace Modules\Receiving\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\MasterData\Models\Warehouse;
use Modules\Purchasing\Models\PoLine;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Receiving\Models\GoodsReceipt;
use RuntimeException;

class ReceivingService
{
    public function __construct(
        private NumberingService $numbering,
        private InventoryTransactionService $its,
        private AuditService $audit,
    ) {}

    public function createAndPost(int $companyId, array $header, array $lines, User $user): GoodsReceipt
    {
        if ($lines === []) {
            throw new RuntimeException('GR wajib punya minimal 1 line.');
        }

        $poLineIds = array_map(static fn (array $line): int => (int) ($line['po_line_id'] ?? 0), $lines);
        if (in_array(0, $poLineIds, true) || count($poLineIds) !== count(array_unique($poLineIds))) {
            throw new RuntimeException('Setiap PO line hanya boleh muncul satu kali dalam satu GR.');
        }

        return DB::transaction(function () use ($companyId, $header, $lines, $user): GoodsReceipt {
            $po = PurchaseOrder::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereKey((int) ($header['purchase_order_id'] ?? 0))
                ->lockForUpdate()
                ->first();
            if ($po === null) {
                throw new RuntimeException('PO tidak ditemukan pada company aktif.');
            }
            if (! in_array($po->status, ['APPROVED', 'PARTIAL_RECEIVED'], true)) {
                throw new RuntimeException('GR hanya dapat dibuat dari PO APPROVED atau PARTIAL_RECEIVED.');
            }

            $warehouse = Warehouse::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereKey((int) ($header['warehouse_id'] ?? 0))
                ->first();
            if ($warehouse === null) {
                throw new RuntimeException('Warehouse tidak ditemukan pada company aktif.');
            }

            $gr = GoodsReceipt::create([
                'company_id' => $companyId,
                'doc_no' => $this->numbering->next($companyId, 'GR'),
                'purchase_order_id' => $po->id,
                'warehouse_id' => $warehouse->id,
                'received_date' => $header['received_date'],
                'delivery_note_no' => $header['delivery_note_no'] ?? null,
                'status' => 'DRAFT',
                'created_by' => $user->id,
            ]);

            $itsLines = [];
            foreach ($lines as $lineData) {
                $qty = (float) ($lineData['qty_received'] ?? 0);
                if ($qty <= 0) {
                    throw new RuntimeException('Qty penerimaan wajib lebih besar dari nol.');
                }

                $poLine = PoLine::query()
                    ->where('purchase_order_id', $po->id)
                    ->whereKey((int) $lineData['po_line_id'])
                    ->lockForUpdate()
                    ->first();
                if ($poLine === null) {
                    throw new RuntimeException('PO line tidak berasal dari PO yang dipilih.');
                }

                $remaining = (float) $poLine->qty - (float) $poLine->received_qty;
                if ($qty - $remaining > 0.0001) {
                    throw new RuntimeException("Qty penerimaan melebihi sisa PO line ({$remaining}).");
                }

                $material = $poLine->material()->withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->first();
                if ($material === null) {
                    throw new RuntimeException('Material PO line tidak berasal dari company aktif.');
                }

                $rolls = $lineData['rolls'] ?? [];
                if ($material->isRollTracked()) {
                    if ($rolls === []) {
                        throw new RuntimeException("BR-052: material fabric [{$material->code}] wajib diinput per roll.");
                    }
                    $rollQty = array_sum(array_map(static fn (array $roll): float => (float) ($roll['qty_buy'] ?? 0), $rolls));
                    if (abs($rollQty - $qty) > 0.0001) {
                        throw new RuntimeException('Total qty_buy seluruh roll harus sama dengan qty_received.');
                    }
                } elseif ($rolls !== []) {
                    throw new RuntimeException('Roll hanya boleh diinput untuk material dengan tracking ROLL.');
                }

                $grLine = $gr->lines()->create([
                    'po_line_id' => $poLine->id,
                    'material_id' => $poLine->material_id,
                    'qty_received' => $qty,
                    'uom_id' => $poLine->uom_id,
                    'unit_price' => $poLine->unit_price,
                    'status' => 'QUALITY_HOLD',
                ]);

                foreach ($rolls as $rollData) {
                    $qtyBuy = (float) ($rollData['qty_buy'] ?? 0);
                    if ($qtyBuy <= 0) {
                        throw new RuntimeException('qty_buy roll wajib lebih besar dari nol.');
                    }
                    $gsm = (float) ($rollData['gsm_actual'] ?? $material->gsm ?? 0);
                    $widthCm = (float) ($rollData['width_actual_cm'] ?? $material->width_cm ?? 0);
                    if ($gsm <= 0 || $widthCm <= 0) {
                        throw new RuntimeException('GSM dan width wajib tersedia untuk konversi roll.');
                    }
                    $conversion = round(1000 / ($gsm * ($widthCm / 100)), 6);
                    $qtyMeter = isset($rollData['qty_meter_actual'])
                        ? (float) $rollData['qty_meter_actual']
                        : round($qtyBuy * $conversion, 4);
                    if ($qtyMeter <= 0) {
                        throw new RuntimeException('qty_meter_actual roll wajib lebih besar dari nol.');
                    }

                    $grLine->rolls()->create([
                        'company_id' => $companyId,
                        'roll_no' => $rollData['roll_no'],
                        'material_id' => $material->id,
                        'lot_no' => $rollData['lot_no'] ?? null,
                        'shade_group_id' => $rollData['shade_group_id'] ?? null,
                        'qty_buy' => $qtyBuy,
                        'qty_meter_actual' => $qtyMeter,
                        'conversion_rate' => $conversion,
                        'gsm_actual' => $rollData['gsm_actual'] ?? null,
                        'width_actual_cm' => $rollData['width_actual_cm'] ?? null,
                        'qty_remaining_meter' => $qtyMeter,
                        'status' => 'QUALITY_HOLD',
                    ]);
                }

                $itsLines[] = [
                    'material_id' => $poLine->material_id,
                    'warehouse_id' => $warehouse->id,
                    'location_id' => $lineData['location_id'] ?? null,
                    'qty' => $qty,
                    'uom_id' => $poLine->uom_id,
                    'unit_cost' => (float) $poLine->unit_price,
                    'source_document_line_id' => $grLine->id,
                ];

                $poLine->received_qty = (float) $poLine->received_qty + $qty;
                $poLine->save();
            }

            $movement = $this->its->post('PURCHASE_RECEIPT', [
                'company_id' => $companyId,
                'source_document_type' => 'goods_receipts',
                'source_document_id' => $gr->id,
            ], $itsLines, $user);

            $gr->update(['status' => 'POSTED', 'updated_by' => $user->id]);
            $po->refreshReceivingStatus();
            $this->audit->record('create', $gr, after: [
                'doc_no' => $gr->doc_no,
                'movement' => $movement->doc_no,
            ]);

            return $gr->load('lines.rolls');
        });
    }
}
