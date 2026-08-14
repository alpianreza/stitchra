<?php

namespace Modules\Shipping\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\Packing\Models\PackingList;
use Modules\Shipping\Models\Shipment;
use RuntimeException;

/**
 * PF-10/BR-021: shipment dari packing list APPROVED; cek toleransi vs SO;
 * di luar toleransi → status flag + butuh approval eksplisit.
 * FG keluar via ITS SHIPMENT (BR-013).
 */
class ShipmentService
{
    public function __construct(
        private NumberingService $numbering,
        private InventoryTransactionService $its,
        private AuditService $audit,
    ) {}

    /** Buat shipment (SI) dari packing list APPROVED — lines = agregat karton. */
    public function create(PackingList $pl, array $header, User $user): Shipment
    {
        if ($pl->status !== 'APPROVED') {
            throw new RuntimeException('Shipment hanya dari packing list APPROVED (finalize dulu).');
        }

        return DB::transaction(function () use ($pl, $header, $user): Shipment {
            $shipment = Shipment::create(array_merge($header, [
                'company_id' => $pl->company_id,
                'doc_no' => $this->numbering->next($pl->company_id, 'SHP'),
                'sales_order_id' => $pl->sales_order_id,
                'packing_list_id' => $pl->id,
                'status' => 'DRAFT',
                'tolerance_check' => 'PENDING',
                'created_by' => $user->id,
            ]));

            $agg = DB::table('carton_lines')
                ->join('cartons', 'cartons.id', '=', 'carton_lines.carton_id')
                ->where('cartons.packing_list_id', $pl->id)
                ->selectRaw('style_id, colorway_id, size_id, SUM(qty) as qty')
                ->groupBy('style_id', 'colorway_id', 'size_id')
                ->get();

            foreach ($agg as $row) {
                $shipment->lines()->create([
                    'style_id' => $row->style_id,
                    'colorway_id' => $row->colorway_id,
                    'size_id' => $row->size_id,
                    'qty_shipped' => (float) $row->qty,
                ]);
            }

            $this->checkTolerance($shipment);   // BR-021

            return $shipment->fresh('lines');
        });
    }

    /** BR-021: bandingkan qty shipped vs SO per matrix terhadap toleransi buyer. */
    public function checkTolerance(Shipment $shipment): void
    {
        $so = $shipment->salesOrder;
        $tolerance = (float) ($so->tolerance_pct ?? $so->customer?->shipment_tolerance_pct ?? 0);

        $result = 'OK';
        foreach ($shipment->lines as $line) {
            $soLine = $so->lines()
                ->where('style_id', $line->style_id)
                ->where('colorway_id', $line->colorway_id)
                ->where('size_id', $line->size_id)
                ->first();

            if ($soLine === null) {
                $result = 'OVER';
                continue;
            }

            $ordered = (float) $soLine->qty;
            $shipped = (float) $line->qty_shipped;
            $diff = $ordered > 0 ? ($shipped - $ordered) / $ordered * 100 : 100;

            if ($diff > $tolerance) {
                $result = 'OVER';
            } elseif ($diff < -$tolerance) {
                $result = $result === 'OVER' ? 'OVER' : 'UNDER';
            }
        }

        $shipment->update(['tolerance_check' => $result]);
    }

    /** Approve shipment yang di luar toleransi (flag eksplisit, tercatat audit). */
    public function approveOverTolerance(Shipment $shipment, User $user): Shipment
    {
        if ($shipment->tolerance_check === 'OK') {
            throw new RuntimeException('Shipment ini dalam toleransi — tidak butuh approval khusus.');
        }

        $shipment->update(['over_tolerance_approved' => true]);
        $this->audit->record('update', $shipment, after: ['over_tolerance_approved' => true, 'tolerance_check' => $shipment->tolerance_check]);

        return $shipment->fresh();
    }

    /**
     * Kirim: wajib toleransi OK atau sudah di-approve (BR-021).
     * FG keluar via ITS SHIPMENT (BR-013/006).
     */
    public function ship(Shipment $shipment, int $fgWarehouseId, User $user): Shipment
    {
        if ($shipment->status !== 'DRAFT' && $shipment->status !== 'READY') {
            throw new RuntimeException("Shipment berstatus {$shipment->status} tidak bisa dikirim.");
        }
        if ($shipment->tolerance_check !== 'OK' && ! $shipment->over_tolerance_approved) {
            throw new RuntimeException('BR-021: shipment di luar toleransi buyer — butuh approveOverTolerance dulu.');
        }

        return DB::transaction(function () use ($shipment, $fgWarehouseId, $user): Shipment {
            $pcsUom = \Modules\MasterData\Models\Uom::where('code', 'like', 'PCS%')->value('id') ?? 1;

            $itsLines = $shipment->lines->map(fn ($l) => [
                'item_type' => 'FG',
                'style_id' => $l->style_id,
                'colorway_id' => $l->colorway_id,
                'size_id' => $l->size_id,
                'warehouse_id' => $fgWarehouseId,
                'qty' => (float) $l->qty_shipped,
                'uom_id' => $pcsUom,
                'source_document_line_id' => $l->id,
            ])->all();

            $this->its->post('SHIPMENT', [
                'company_id' => $shipment->company_id,
                'source_document_type' => 'shipments',
                'source_document_id' => $shipment->id,
            ], $itsLines, $user);

            $shipment->update(['status' => 'SHIPPED', 'updated_by' => $user->id]);
            $shipment->packingList?->update(['status' => 'SHIPPED']);

            // SO → CLOSED bila seluruh qty terkirim (dalam toleransi)
            $so = $shipment->salesOrder;
            $totalShipped = (float) DB::table('shipment_lines')
                ->join('shipments', 'shipments.id', '=', 'shipment_lines.shipment_id')
                ->where('shipments.sales_order_id', $so->id)
                ->where('shipments.status', 'SHIPPED')
                ->sum('shipment_lines.qty_shipped');
            $totalOrdered = (float) $so->lines()->sum('qty');
            $tolerance = (float) ($so->tolerance_pct ?? $so->customer?->shipment_tolerance_pct ?? 0);

            if ($totalShipped >= $totalOrdered * (1 - $tolerance / 100)) {
                $so->update(['status' => 'CLOSED']);
            } elseif ($so->status === 'CONFIRMED') {
                $so->update(['status' => 'IN_PROGRESS']);
            }

            $this->audit->record('update', $shipment, after: ['status' => 'SHIPPED', 'so' => $so->doc_no]);

            return $shipment->fresh();
        });
    }
}
