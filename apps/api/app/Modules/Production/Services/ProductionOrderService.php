<?php

namespace Modules\Production\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Approval\ApprovalEngine;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockReservation;
use Modules\ProductDev\Models\Bom;
use Modules\ProductDev\Models\Routing;
use Modules\Production\Models\ProductionOrder;
use Modules\Sales\Models\SalesOrder;
use RuntimeException;

/**
 * Manufacturing Order.
 * BR-060: MO release = HARD RESERVATION (saldo reserved ↑ + stock_reservations).
 * BR-040: shortage → error berisi daftar kurang; planner selesaikan manual.
 * BR-030: MO menyimpan snapshot bom_version_id/routing_version_id.
 * BR-010/015: nomor MO via numbering; release via approval flow.
 */
class ProductionOrderService
{
    public function __construct(
        private NumberingService $numbering,
        private ApprovalEngine $approval,
        private AuditService $audit,
    ) {}

    /** Generate MO dari SO CONFIRMED — satu MO per style (qty diagregasi dari matrix lines). */
    public function createFromSalesOrder(SalesOrder $so, User $creator): array
    {
        if ($so->status !== 'CONFIRMED') {
            throw new RuntimeException('MO hanya bisa dibuat dari SO CONFIRMED (BR-023).');
        }

        return DB::transaction(function () use ($so, $creator): array {
            $mos = [];
            $qtyPerStyle = $so->lines->groupBy('style_id')->map(fn ($l) => $l->sum(fn ($x) => (float) $x->qty));

            foreach ($qtyPerStyle as $styleId => $qty) {
                // Cegah duplikasi MO untuk style yang sama di SO yang sama
                $exists = ProductionOrder::where('sales_order_id', $so->id)->where('style_id', $styleId)->exists();
                if ($exists) {
                    continue;
                }

                $bomVersion = Bom::where('style_id', $styleId)->first()?->approvedVersion();
                $routingVersion = Routing::where('style_id', $styleId)->first()?->approvedVersion();

                if ($bomVersion === null || $routingVersion === null) {
                    throw new RuntimeException("Style #{$styleId} belum punya BOM/Routing APPROVED (BR-023).");
                }

                $mos[] = ProductionOrder::create([
                    'company_id' => $so->company_id,
                    'doc_no' => $this->numbering->next($so->company_id, 'MO'),
                    'sales_order_id' => $so->id,
                    'style_id' => $styleId,
                    'bom_version_id' => $bomVersion->id,      // snapshot (BR-030)
                    'routing_version_id' => $routingVersion->id,
                    'qty_planned' => $qty,
                    'status' => 'PLANNED',
                    'created_by' => $creator->id,
                ]);
            }

            if (empty($mos)) {
                throw new RuntimeException('Semua style di SO ini sudah punya MO.');
            }

            $this->audit->record('create', 'production_orders', after: [
                'sales_order' => $so->doc_no, 'mo_count' => count($mos),
            ]);

            return $mos;
        });
    }

    /**
     * BR-060: release = hard reservation per BOM line × qty_planned.
     * BR-040: saldo kurang → RuntimeException berisi daftar shortage; TIDAK ada reservasi parsial (atomic).
     */
    public function release(ProductionOrder $mo, int $warehouseId, User $user): ProductionOrder
    {
        if ($mo->status !== 'PLANNED') {
            throw new RuntimeException('Hanya MO PLANNED yang bisa di-release.');
        }

        return DB::transaction(function () use ($mo, $warehouseId, $user): ProductionOrder {
            $bomLines = $mo->bomVersion->lines;
            $shortages = [];
            $toReserve = [];

            // 1. Kalkulasi kebutuhan + validasi available (lock saldo)
            foreach ($bomLines as $bomLine) {
                $required = round($bomLine->grossPerPcs() * (float) $mo->qty_planned, 4);

                $balance = StockBalance::withoutGlobalScopes()
                    ->where('company_id', $mo->company_id)
                    ->where('material_id', $bomLine->material_id)
                    ->where('warehouse_id', $warehouseId)
                    ->whereNull('location_id')->whereNull('lot_no')->whereNull('roll_id')
                    ->lockForUpdate()
                    ->first();

                $available = $balance ? $balance->available() : 0.0;

                if ($available < $required) {
                    $material = $bomLine->material;
                    $shortages[] = sprintf(
                        '%s (%s): butuh %s, available %s, kurang %s',
                        $material->code, $material->name,
                        rtrim(rtrim(number_format($required, 4), '0'), '.'),
                        rtrim(rtrim(number_format($available, 4), '0'), '.'),
                        rtrim(rtrim(number_format($required - $available, 4), '0'), '.'),
                    );
                }

                $toReserve[] = ['bom_line' => $bomLine, 'required' => $required, 'balance' => $balance];
            }

            // BR-040: shortage → tolak TANPA efek samping (rollback via exception)
            if (! empty($shortages)) {
                throw new RuntimeException('BR-040: material shortage saat release MO:\n- '.implode("\n- ", $shortages));
            }

            // 2. Semua cukup → buat reservasi + naikkan saldo reserved
            foreach ($toReserve as $r) {
                $bomLine = $r['bom_line'];
                $balance = $r['balance'];

                StockReservation::create([
                    'company_id' => $mo->company_id,
                    'mo_id' => $mo->id,
                    'material_id' => $bomLine->material_id,
                    'warehouse_id' => $warehouseId,
                    'qty_reserved' => $r['required'],
                    'status' => 'ACTIVE',
                    'created_by' => $user->id,
                ]);

                $balance->reserved = (float) $balance->reserved + $r['required'];
                $balance->save();

                // Alokasi material MO (BR-060 pasangan reservasi)
                $mo->materialAllocations()->create([
                    'material_id' => $bomLine->material_id,
                    'qty_required' => $r['required'],
                    'qty_reserved' => $r['required'],
                    'is_backflush' => (bool) $bomLine->is_backflush,   // BR-041
                ]);
            }

            $mo->update(['status' => 'RELEASED']);

            $this->audit->record('update', $mo, after: ['status' => 'RELEASED', 'reservations' => count($toReserve)]);

            return $mo->fresh('materialAllocations');
        });
    }

    /** Batalkan release: lepas semua reservasi aktif, saldo reserved turun. */
    public function unrelease(ProductionOrder $mo, User $user): ProductionOrder
    {
        if ($mo->status !== 'RELEASED') {
            throw new RuntimeException('Hanya MO RELEASED yang bisa di-unrelease.');
        }

        return DB::transaction(function () use ($mo, $user): ProductionOrder {
            $reservations = StockReservation::where('mo_id', $mo->id)->whereIn('status', ['ACTIVE', 'PARTIAL_ISSUED'])->get();

            foreach ($reservations as $res) {
                $remaining = $res->remaining();
                if ($remaining <= 0) {
                    continue;
                }

                $balance = StockBalance::withoutGlobalScopes()
                    ->where('company_id', $mo->company_id)
                    ->where('material_id', $res->material_id)
                    ->where('warehouse_id', $res->warehouse_id)
                    ->lockForUpdate()
                    ->first();

                if ($balance) {
                    $balance->reserved = max(0.0, (float) $balance->reserved - $remaining);
                    $balance->save();
                }

                $res->update(['status' => 'RELEASED']);

                $mo->materialAllocations()->where('material_id', $res->material_id)
                    ->update(['qty_reserved' => 0]);
            }

            $mo->update(['status' => 'PLANNED']);

            $this->audit->record('update', $mo, after: ['status' => 'PLANNED', 'released_reservations' => $reservations->count()]);

            return $mo->fresh();
        });
    }

    public function submit(ProductionOrder $mo, User $submitter): void
    {
        $this->approval->submit($mo, 'MO', $submitter);
    }
}
