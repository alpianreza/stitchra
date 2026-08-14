<?php

namespace Modules\Receiving\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\Receiving\Models\GoodsReceipt;
use RuntimeException;

/**
 * Goods Receipt — BR-003/052 (fabric per roll), BR-002 (dual UOM tersimpan),
 * BR-004 (masuk QUALITY_HOLD), BR-013 (stok HANYA via ITS, atomic),
 * BR-051 (partial receiving → po_lines.received_qty).
 */
class ReceivingService
{
    public function __construct(
        private NumberingService $numbering,
        private InventoryTransactionService $its,
        private AuditService $audit,
    ) {}

    /**
     * Buat + posting GR. $lines[]: po_line_id, qty_received, uom_id, unit_price,
     *   rolls[] (wajib untuk fabric): roll_no, lot_no, shade_group_id, qty_buy,
     *   qty_meter_actual, gsm_actual, width_actual_cm
     */
    public function createAndPost(int $companyId, array $header, array $lines, User $user): GoodsReceipt
    {
        if (empty($lines)) {
            throw new RuntimeException('GR wajib punya minimal 1 line.');
        }

        return DB::transaction(function () use ($companyId, $header, $lines, $user): GoodsReceipt {
            $gr = GoodsReceipt::create(array_merge($header, [
                'company_id' => $companyId,
                'doc_no' => $this->numbering->next($companyId, 'GR'),
                'status' => 'DRAFT',
                'created_by' => $user->id,
            ]));

            $itsLines = [];

            foreach ($lines as $lineData) {
                $rolls = $lineData['rolls'] ?? [];
                unset($lineData['rolls']);

                $grLine = $gr->lines()->create(array_merge($lineData, ['status' => 'QUALITY_HOLD']));

                $material = $grLine->material()->first();

                // BR-052/003: fabric wajib roll-level — satu fabric_rolls per roll
                if ($material->isRollTracked()) {
                    if (empty($rolls)) {
                        throw new RuntimeException("BR-052: material fabric [{$material->code}] wajib diinput per roll.");
                    }

                    foreach ($rolls as $rollData) {
                        // BR-002: konversi tersimpan per roll (meter = kg×1000/(GSM×lebar_m))
                        $conversion = $material->kgToMeter(1) ?? 1; // rate per 1 unit beli
                        $qtyMeter = isset($rollData['qty_meter_actual'])
                            ? (float) $rollData['qty_meter_actual']
                            : round((float) $rollData['qty_buy'] * $conversion, 4);

                        $grLine->rolls()->create([
                            'company_id' => $companyId,
                            'roll_no' => $rollData['roll_no'],
                            'material_id' => $material->id,
                            'lot_no' => $rollData['lot_no'] ?? null,
                            'shade_group_id' => $rollData['shade_group_id'] ?? null,
                            'qty_buy' => $rollData['qty_buy'],
                            'qty_meter_actual' => $qtyMeter,
                            'conversion_rate' => $conversion,
                            'gsm_actual' => $rollData['gsm_actual'] ?? null,
                            'width_actual_cm' => $rollData['width_actual_cm'] ?? null,
                            'qty_remaining_meter' => $qtyMeter,
                            'status' => 'QUALITY_HOLD',
                        ]);
                    }
                }

                // BR-004/013: posting ke stok via ITS — masuk sebagai QUALITY_HOLD
                $itsLines[] = [
                    'material_id' => $grLine->material_id,
                    'warehouse_id' => $gr->warehouse_id,
                    'location_id' => $lineData['location_id'] ?? null,
                    'qty' => (float) $grLine->qty_received,
                    'uom_id' => $grLine->uom_id,
                    'unit_cost' => (float) $grLine->unit_price,
                    'source_document_line_id' => $grLine->id,
                ];

                // BR-051: update received_qty di PO line
                $grLine->poLine()->lockForUpdate()->first()?->increment('received_qty', (float) $grLine->qty_received);
            }

            $movement = $this->its->post('PURCHASE_RECEIPT', [
                'company_id' => $companyId,
                'source_document_type' => 'goods_receipts',
                'source_document_id' => $gr->id,
            ], $itsLines, $user);

            $gr->update(['status' => 'POSTED']);
            $gr->purchaseOrder->refreshReceivingStatus();   // BR-051: PARTIAL_RECEIVED / RECEIVED

            $this->audit->record('create', $gr, after: ['doc_no' => $gr->doc_no, 'movement' => $movement->doc_no]);

            return $gr->load('lines.rolls');
        });
    }
}
