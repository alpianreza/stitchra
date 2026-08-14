<?php

namespace Modules\Purchasing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Purchasing\Models\SupplierInvoice;

/**
 * BR-050: 3-way match — invoice vs PO (harga, toleransi dari approval matrix)
 * vs GR (qty diterima). Hasil: MATCHED / MISMATCH.
 */
class ThreeWayMatchService
{
    public function match(SupplierInvoice $invoice, float $priceTolerancePct = 0.0, float $qtyTolerancePct = 0.0): SupplierInvoice
    {
        return DB::transaction(function () use ($invoice, $priceTolerancePct, $qtyTolerancePct): SupplierInvoice {
            $mismatches = [];

            foreach ($invoice->lines as $line) {
                $poLine = $line->po_line_id
                    ? DB::table('po_lines')->where('id', $line->po_line_id)->first()
                    : null;

                if ($poLine === null) {
                    $mismatches[] = "Line {$line->id}: tidak terkait PO line";
                    continue;
                }

                // Match harga: invoice vs PO
                $poPrice = (float) $poLine->unit_price;
                $invPrice = (float) $line->unit_price;
                if ($poPrice > 0 && abs($invPrice - $poPrice) / $poPrice * 100 > $priceTolerancePct) {
                    $mismatches[] = "Line {$line->id}: harga invoice {$invPrice} vs PO {$poPrice} melebihi toleransi {$priceTolerancePct}%";
                }

                // Match qty: invoice vs GR (received)
                $receivedQty = (float) $poLine->received_qty;
                $invQty = (float) $line->qty;
                if ($receivedQty > 0 && abs($invQty - $receivedQty) / $receivedQty * 100 > $qtyTolerancePct) {
                    $mismatches[] = "Line {$line->id}: qty invoice {$invQty} vs received {$receivedQty} melebihi toleransi {$qtyTolerancePct}%";
                }
            }

            $invoice->update([
                'match_status' => empty($mismatches) ? 'MATCHED' : 'MISMATCH',
            ]);

            return $invoice->fresh();
        });
    }
}
