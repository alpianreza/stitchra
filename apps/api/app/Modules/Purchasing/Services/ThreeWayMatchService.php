<?php

namespace Modules\Purchasing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Models\SupplierInvoice;
use RuntimeException;

class ThreeWayMatchService
{
    public function match(SupplierInvoice $invoice, float $priceTolerancePct = 0.0, float $qtyTolerancePct = 0.0): SupplierInvoice
    {
        if ($priceTolerancePct < 0 || $priceTolerancePct > 100 || $qtyTolerancePct < 0 || $qtyTolerancePct > 100) {
            throw new RuntimeException('Tolerance 3-way match harus berada di antara 0 dan 100 persen.');
        }

        return DB::transaction(function () use ($invoice, $priceTolerancePct, $qtyTolerancePct): SupplierInvoice {
            $locked = SupplierInvoice::withoutGlobalScopes()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $locked->load('lines');
            $mismatches = [];

            $po = $locked->purchase_order_id
                ? PurchaseOrder::withoutGlobalScopes()
                    ->where('company_id', $locked->company_id)
                    ->whereKey($locked->purchase_order_id)
                    ->first()
                : null;

            if ($po === null) {
                $mismatches[] = 'Invoice tidak terkait PO pada company yang sama';
            } elseif ((int) $po->supplier_id !== (int) $locked->supplier_id) {
                $mismatches[] = 'Supplier invoice tidak sama dengan supplier PO';
            }
            if ($locked->lines->isEmpty()) {
                $mismatches[] = 'Invoice tidak memiliki line';
            }

            $seen = [];
            foreach ($locked->lines as $line) {
                if (isset($seen[$line->po_line_id])) {
                    $mismatches[] = "Line {$line->id}: PO line duplikat pada invoice";
                    continue;
                }
                $seen[$line->po_line_id] = true;

                $poLine = $po && $line->po_line_id
                    ? DB::table('po_lines')
                        ->where('purchase_order_id', $po->id)
                        ->where('id', $line->po_line_id)
                        ->first()
                    : null;
                if ($poLine === null) {
                    $mismatches[] = "Line {$line->id}: tidak terkait PO line pada PO invoice";
                    continue;
                }

                $poPrice = (float) $poLine->unit_price;
                $invPrice = (float) $line->unit_price;
                $priceVariance = $poPrice > 0
                    ? abs($invPrice - $poPrice) / $poPrice * 100
                    : ($invPrice === 0.0 ? 0.0 : INF);
                if ($priceVariance > $priceTolerancePct) {
                    $mismatches[] = "Line {$line->id}: harga invoice melebihi toleransi {$priceTolerancePct}%";
                }

                $receivedQty = (float) $poLine->received_qty;
                $invQty = (float) $line->qty;
                if ($receivedQty <= 0) {
                    $mismatches[] = "Line {$line->id}: belum ada qty yang diterima";
                    continue;
                }
                $qtyVariance = abs($invQty - $receivedQty) / $receivedQty * 100;
                if ($qtyVariance > $qtyTolerancePct) {
                    $mismatches[] = "Line {$line->id}: qty invoice melebihi toleransi {$qtyTolerancePct}%";
                }
            }

            $locked->update(['match_status' => $mismatches === [] ? 'MATCHED' : 'MISMATCH']);
            return $locked->fresh();
        });
    }
}
