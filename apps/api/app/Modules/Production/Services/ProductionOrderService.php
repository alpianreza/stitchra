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
    public function __construct(private NumberingService $numbering, private ApprovalEngine $approval, private AuditService $audit, private StandardCostSnapshotService $costSnapshots) {}

    public function createFromSalesOrder(SalesOrder $so, User $creator): array
    {
        return DB::transaction(function () use ($so, $creator): array {
            $lockedSo = SalesOrder::withoutGlobalScopes()->with('lines')->whereKey($so->id)->lockForUpdate()->firstOrFail();
            $this->access($creator, (int) $lockedSo->company_id);
            if ($lockedSo->status !== 'CONFIRMED') throw new RuntimeException('MO hanya bisa dibuat dari SO CONFIRMED (BR-023).');
            $mos = [];
            foreach ($lockedSo->lines->groupBy('style_id') as $styleId => $matrixLines) {
                if (ProductionOrder::withoutGlobalScopes()->where('company_id', $lockedSo->company_id)->where('sales_order_id', $lockedSo->id)->where('style_id', $styleId)->exists()) continue;
                $bom = Bom::where('style_id', $styleId)->first()?->approvedVersion(); $routing = Routing::where('style_id', $styleId)->first()?->approvedVersion();
                if (! $bom || ! $routing) throw new RuntimeException("Style #{$styleId} belum punya BOM/Routing APPROVED (BR-023).");
                $qty = $matrixLines->sum(fn ($line) => (float) $line->qty);
                if ($qty <= 0) throw new RuntimeException('BR-020: total matrix MO wajib lebih besar dari nol.');
                $mo = ProductionOrder::create(['company_id' => $lockedSo->company_id, 'doc_no' => $this->numbering->next($lockedSo->company_id, 'MO'),
                    'sales_order_id' => $lockedSo->id, 'style_id' => $styleId, 'bom_version_id' => $bom->id,
                    'routing_version_id' => $routing->id, 'qty_planned' => $qty, 'status' => 'PLANNED', 'created_by' => $creator->id]);
                foreach ($matrixLines as $sourceLine) {
                    $mo->matrixLines()->create([
                        'company_id' => $lockedSo->company_id,
                        'sales_order_line_id' => $sourceLine->id,
                        'colorway_id' => $sourceLine->colorway_id,
                        'size_id' => $sourceLine->size_id,
                        'qty_planned' => $sourceLine->qty,
                    ]);
                }
                $mos[] = $this->costSnapshots->attachIfAvailable($mo);
            }
            if ($mos === []) throw new RuntimeException('Semua style di SO ini sudah punya MO.');
            $this->audit->record('create', 'production_orders', documentId: (int) ($mos[0]->id ?? 0), companyId: (int) $lockedSo->company_id, after: ['sales_order' => $lockedSo->doc_no, 'mo_count' => count($mos), 'matrix_source' => 'SALES_ORDER_LINES']);
            return $mos;
        });
    }

    public function release(ProductionOrder $mo, int $warehouseId, User $user): ProductionOrder
    {
        return DB::transaction(function () use ($mo, $warehouseId, $user): ProductionOrder {
            $locked = ProductionOrder::withoutGlobalScopes()->with('bomVersion.lines.material', 'matrixLines')->whereKey($mo->id)->lockForUpdate()->firstOrFail();
            $this->access($user, (int) $locked->company_id);
            if ($locked->status !== 'PLANNED') throw new RuntimeException('Hanya MO PLANNED yang bisa di-release.');
            if ($locked->matrixLines->isNotEmpty() && abs((float) $locked->matrixLines->sum('qty_planned') - (float) $locked->qty_planned) > 0.0001) throw new RuntimeException('BR-020: total matrix MO tidak sama dengan qty planned MO.');
            $locked = $this->costSnapshots->requireForRelease($locked)->load('bomVersion.lines.material');
            if (! DB::table('warehouses')->where('id', $warehouseId)->where('company_id', $locked->company_id)->exists()) throw new RuntimeException('Warehouse tidak ditemukan pada company MO.');
            if (StockReservation::withoutGlobalScopes()->where('mo_id', $locked->id)->whereIn('status', ['ACTIVE', 'PARTIAL_ISSUED'])->exists()) throw new RuntimeException('MO masih memiliki reservasi aktif.');

            $needs = [];
            foreach ($locked->bomVersion->lines as $line) {
                $id = (int) $line->material_id; $backflush = (bool) $line->is_backflush; $stage = $line->backflush_stage; $uomId = (int) $line->uom_id;
                if ($backflush && $line->material->isFabric()) throw new RuntimeException('BR-066: fabric tidak boleh BACKFLUSH.');
                if ($backflush && (! $stage || ! in_array($stage, NamedProductionMeasureService::BACKFLUSH_STAGES, true))) throw new RuntimeException('BR-066: Named Stage BACKFLUSH belum dikonfigurasi.');
                if (! isset($needs[$id])) $needs[$id] = ['required' => 0.0, 'is_backflush' => $backflush, 'backflush_stage' => $stage, 'uom_id' => $uomId, 'material' => $line->material];
                elseif ($needs[$id]['is_backflush'] !== $backflush || $needs[$id]['backflush_stage'] !== $stage || $needs[$id]['uom_id'] !== $uomId) throw new RuntimeException('BR-066: method, Named Stage, atau UOM material BOM tidak konsisten.');
                $needs[$id]['required'] += $line->grossPerPcs() * (float) $locked->qty_planned;
            }

            $plans = []; $short = [];
            foreach ($needs as $id => $need) {
                $required = round($need['required'], 4); $remaining = $required;
                $balances = StockBalance::withoutGlobalScopes()->where('company_id', $locked->company_id)->where('material_id', $id)
                    ->where('warehouse_id', $warehouseId)->whereRaw('on_hand > reserved + quality_hold')->orderBy('id')->lockForUpdate()->get();
                foreach ($balances as $balance) { $available = max(0, $balance->available()); if ($available <= 0 || $remaining <= 0) continue;
                    $qty = min($available, $remaining); $plans[] = ['material_id' => $id, 'balance' => $balance, 'qty' => $qty]; $remaining = round($remaining - $qty, 4); }
                if ($remaining > 0.0001) { $material = $need['material']; $short[] = sprintf('%s (%s): butuh %s, available %s, kurang %s', $material->code, $material->name, $required, $required - $remaining, $remaining); }
            }
            if ($short !== []) throw new RuntimeException('BR-040: material shortage saat release MO:\n- '.implode("\n- ", $short));

            foreach ($plans as $plan) { $balance = $plan['balance']; StockReservation::create(['company_id' => $locked->company_id, 'mo_id' => $locked->id,
                'material_id' => $plan['material_id'], 'warehouse_id' => $warehouseId, 'location_id' => $balance->location_id,
                'lot_no' => $balance->lot_no, 'roll_id' => $balance->roll_id, 'ownership' => $balance->ownership,
                'qty_reserved' => $plan['qty'], 'status' => 'ACTIVE', 'created_by' => $user->id]);
                $balance->reserved = (float) $balance->reserved + $plan['qty']; $balance->save(); }
            foreach ($needs as $id => $need) { $required = round($need['required'], 4); $locked->materialAllocations()->updateOrCreate(['material_id' => $id], [
                'uom_id' => $need['uom_id'], 'qty_required' => $required, 'qty_reserved' => $required, 'qty_issued' => 0,
                'is_backflush' => $need['is_backflush'], 'backflush_stage' => $need['is_backflush'] ? $need['backflush_stage'] : null]); }
            $locked->update(['status' => 'RELEASED', 'updated_by' => $user->id]);
            $this->audit->record('update', $locked, after: ['status' => 'RELEASED', 'reservations' => count($plans), 'consumption_policy' => 'BR-066']);
            return $locked->fresh('materialAllocations');
        });
    }

    public function unrelease(ProductionOrder $mo, User $user): ProductionOrder
    {
        return DB::transaction(function () use ($mo, $user): ProductionOrder {
            $locked = ProductionOrder::withoutGlobalScopes()->whereKey($mo->id)->lockForUpdate()->firstOrFail(); $this->access($user, (int) $locked->company_id);
            if ($locked->status !== 'RELEASED') throw new RuntimeException('Hanya MO RELEASED yang bisa di-unrelease.');
            $reservations = StockReservation::withoutGlobalScopes()->where('mo_id', $locked->id)->whereIn('status', ['ACTIVE', 'PARTIAL_ISSUED'])->lockForUpdate()->get();
            if ($reservations->contains(fn ($row) => (float) $row->qty_issued > 0)) throw new RuntimeException('MO yang sudah memiliki material issue tidak dapat di-unrelease.');
            foreach ($reservations as $reservation) { $remaining = $reservation->remaining(); if ($remaining <= 0) continue;
                $balance = StockBalance::withoutGlobalScopes()->where('company_id', $locked->company_id)->where('material_id', $reservation->material_id)
                    ->where('warehouse_id', $reservation->warehouse_id)->where('location_id', $reservation->location_id)->where('lot_no', $reservation->lot_no)
                    ->where('roll_id', $reservation->roll_id)->where('ownership', $reservation->ownership)->lockForUpdate()->firstOrFail();
                if ((float) $balance->reserved + 0.0001 < $remaining) throw new RuntimeException('Saldo reserved tidak konsisten dengan reservation.');
                $balance->reserved = (float) $balance->reserved - $remaining; $balance->save(); $reservation->update(['status' => 'RELEASED']); }
            $locked->materialAllocations()->update(['qty_reserved' => 0]); $locked->update(['status' => 'PLANNED', 'updated_by' => $user->id]);
            $this->costSnapshots->verify($locked->fresh()); $this->audit->record('update', $locked, after: ['status' => 'PLANNED', 'released_reservations' => $reservations->count()]);
            return $locked->fresh();
        });
    }

    public function submit(ProductionOrder $mo, User $user): void { $this->approval->submit($mo, 'MO', $user); }
    private function access(User $user, int $companyId): void { if ((int) $user->company_id !== $companyId && ! $user->companies()->whereKey($companyId)->exists()) throw new RuntimeException('User tidak memiliki akses ke company MO.'); }
}
