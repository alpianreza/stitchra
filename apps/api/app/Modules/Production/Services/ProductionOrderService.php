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

class ProductionOrderService
{
    public function __construct(
        private NumberingService $numbering,
        private ApprovalEngine $approval,
        private AuditService $audit,
    ) {}

    public function createFromSalesOrder(SalesOrder $so, User $creator): array
    {
        return DB::transaction(function () use ($so, $creator): array {
            $lockedSo = SalesOrder::withoutGlobalScopes()->with('lines')
                ->whereKey($so->id)->lockForUpdate()->firstOrFail();
            $this->assertUserCompany($creator, (int) $lockedSo->company_id);
            if ($lockedSo->status !== 'CONFIRMED') {
                throw new RuntimeException('MO hanya bisa dibuat dari SO CONFIRMED (BR-023).');
            }

            $mos = [];
            $qtyPerStyle = $lockedSo->lines->groupBy('style_id')
                ->map(fn ($lines) => $lines->sum(fn ($line) => (float) $line->qty));
            foreach ($qtyPerStyle as $styleId => $qty) {
                if (ProductionOrder::withoutGlobalScopes()
                    ->where('company_id', $lockedSo->company_id)
                    ->where('sales_order_id', $lockedSo->id)
                    ->where('style_id', $styleId)->exists()) {
                    continue;
                }

                $bomVersion = Bom::where('style_id', $styleId)->first()?->approvedVersion();
                $routingVersion = Routing::where('style_id', $styleId)->first()?->approvedVersion();
                if ($bomVersion === null || $routingVersion === null) {
                    throw new RuntimeException("Style #{$styleId} belum punya BOM/Routing APPROVED (BR-023).");
                }

                $mos[] = ProductionOrder::create([
                    'company_id' => $lockedSo->company_id,
                    'doc_no' => $this->numbering->next($lockedSo->company_id, 'MO'),
                    'sales_order_id' => $lockedSo->id,
                    'style_id' => $styleId,
                    'bom_version_id' => $bomVersion->id,
                    'routing_version_id' => $routingVersion->id,
                    'qty_planned' => $qty,
                    'status' => 'PLANNED',
                    'created_by' => $creator->id,
                ]);
            }

            if ($mos === []) {
                throw new RuntimeException('Semua style di SO ini sudah punya MO.');
            }
            $this->audit->record('create', 'production_orders', after: [
                'sales_order' => $lockedSo->doc_no,
                'mo_count' => count($mos),
            ]);
            return $mos;
        });
    }

    public function release(ProductionOrder $mo, int $warehouseId, User $user): ProductionOrder
    {
        return DB::transaction(function () use ($mo, $warehouseId, $user): ProductionOrder {
            $locked = ProductionOrder::withoutGlobalScopes()->with('bomVersion.lines.material')
                ->whereKey($mo->id)->lockForUpdate()->firstOrFail();
            $this->assertUserCompany($user, (int) $locked->company_id);
            if ($locked->status !== 'PLANNED') {
                throw new RuntimeException('Hanya MO PLANNED yang bisa di-release.');
            }
            if (! DB::table('warehouses')->where('id', $warehouseId)
                ->where('company_id', $locked->company_id)->exists()) {
                throw new RuntimeException('Warehouse tidak ditemukan pada company MO.');
            }
            if (StockReservation::withoutGlobalScopes()->where('mo_id', $locked->id)
                ->whereIn('status', ['ACTIVE', 'PARTIAL_ISSUED'])->exists()) {
                throw new RuntimeException('MO masih memiliki reservasi aktif.');
            }

            $needs = [];
            foreach ($locked->bomVersion->lines as $bomLine) {
                $materialId = (int) $bomLine->material_id;
                $needs[$materialId] ??= ['required' => 0.0, 'backflush' => true, 'material' => $bomLine->material];
                $needs[$materialId]['required'] += $bomLine->grossPerPcs() * (float) $locked->qty_planned;
                $needs[$materialId]['backflush'] = $needs[$materialId]['backflush'] && (bool) $bomLine->is_backflush;
            }

            $plans = [];
            $shortages = [];
            foreach ($needs as $materialId => $need) {
                $required = round($need['required'], 4);
                $remaining = $required;
                $balances = StockBalance::withoutGlobalScopes()
                    ->where('company_id', $locked->company_id)
                    ->where('material_id', $materialId)
                    ->where('warehouse_id', $warehouseId)
                    ->whereRaw('on_hand > reserved + quality_hold')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($balances as $balance) {
                    $available = max(0.0, $balance->available());
                    if ($available <= 0 || $remaining <= 0) continue;
                    $qty = min($available, $remaining);
                    $plans[] = ['material_id' => $materialId, 'balance' => $balance, 'qty' => $qty];
                    $remaining = round($remaining - $qty, 4);
                }

                if ($remaining > 0.0001) {
                    $material = $need['material'];
                    $available = $required - $remaining;
                    $shortages[] = sprintf(
                        '%s (%s): butuh %s, available %s, kurang %s',
                        $material->code, $material->name,
                        rtrim(rtrim(number_format($required, 4), '0'), '.'),
                        rtrim(rtrim(number_format($available, 4), '0'), '.'),
                        rtrim(rtrim(number_format($remaining, 4), '0'), '.'),
                    );
                }
            }

            if ($shortages !== []) {
                throw new RuntimeException('BR-040: material shortage saat release MO:\n- '.implode("\n- ", $shortages));
            }

            foreach ($plans as $plan) {
                $balance = $plan['balance'];
                StockReservation::create([
                    'company_id' => $locked->company_id,
                    'mo_id' => $locked->id,
                    'material_id' => $plan['material_id'],
                    'warehouse_id' => $warehouseId,
                    'location_id' => $balance->location_id,
                    'lot_no' => $balance->lot_no,
                    'roll_id' => $balance->roll_id,
                    'ownership' => $balance->ownership,
                    'qty_reserved' => $plan['qty'],
                    'status' => 'ACTIVE',
                    'created_by' => $user->id,
                ]);
                $balance->reserved = (float) $balance->reserved + $plan['qty'];
                $balance->save();
            }

            foreach ($needs as $materialId => $need) {
                $required = round($need['required'], 4);
                $locked->materialAllocations()->updateOrCreate(
                    ['material_id' => $materialId],
                    ['qty_required' => $required, 'qty_reserved' => $required, 'qty_issued' => 0, 'is_backflush' => $need['backflush']],
                );
            }

            $locked->update(['status' => 'RELEASED', 'updated_by' => $user->id]);
            $this->audit->record('update', $locked, after: ['status' => 'RELEASED', 'reservations' => count($plans)]);
            return $locked->fresh('materialAllocations');
        });
    }

    public function unrelease(ProductionOrder $mo, User $user): ProductionOrder
    {
        return DB::transaction(function () use ($mo, $user): ProductionOrder {
            $locked = ProductionOrder::withoutGlobalScopes()->whereKey($mo->id)->lockForUpdate()->firstOrFail();
            $this->assertUserCompany($user, (int) $locked->company_id);
            if ($locked->status !== 'RELEASED') {
                throw new RuntimeException('Hanya MO RELEASED yang bisa di-unrelease.');
            }

            $reservations = StockReservation::withoutGlobalScopes()->where('mo_id', $locked->id)
                ->whereIn('status', ['ACTIVE', 'PARTIAL_ISSUED'])->lockForUpdate()->get();
            if ($reservations->contains(fn ($reservation) => (float) $reservation->qty_issued > 0)) {
                throw new RuntimeException('MO yang sudah memiliki material issue tidak dapat di-unrelease.');
            }

            foreach ($reservations as $reservation) {
                $remaining = $reservation->remaining();
                if ($remaining <= 0) continue;
                $balance = StockBalance::withoutGlobalScopes()
                    ->where('company_id', $locked->company_id)
                    ->where('material_id', $reservation->material_id)
                    ->where('warehouse_id', $reservation->warehouse_id)
                    ->where('location_id', $reservation->location_id)
                    ->where('lot_no', $reservation->lot_no)
                    ->where('roll_id', $reservation->roll_id)
                    ->where('ownership', $reservation->ownership)
                    ->lockForUpdate()->firstOrFail();
                if ((float) $balance->reserved + 0.0001 < $remaining) {
                    throw new RuntimeException('Saldo reserved tidak konsisten dengan reservation.');
                }
                $balance->reserved = (float) $balance->reserved - $remaining;
                $balance->save();
                $reservation->update(['status' => 'RELEASED']);
            }

            $locked->materialAllocations()->update(['qty_reserved' => 0]);
            $locked->update(['status' => 'PLANNED', 'updated_by' => $user->id]);
            $this->audit->record('update', $locked, after: ['status' => 'PLANNED', 'released_reservations' => $reservations->count()]);
            return $locked->fresh();
        });
    }

    public function submit(ProductionOrder $mo, User $submitter): void
    {
        $this->approval->submit($mo, 'MO', $submitter);
    }

    private function assertUserCompany(User $user, int $companyId): void
    {
        if ((int) $user->company_id !== $companyId && ! $user->companies()->whereKey($companyId)->exists()) {
            throw new RuntimeException('User tidak memiliki akses ke company MO.');
        }
    }
}
