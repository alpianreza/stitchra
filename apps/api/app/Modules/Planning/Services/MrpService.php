<?php

namespace Modules\Planning\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Inventory\Models\StockBalance;
use Modules\MasterData\Models\Material;
use Modules\Planning\Models\MrpRequirement;
use Modules\Planning\Models\MrpRun;
use Modules\ProductDev\Models\Bom;
use Modules\Sales\Models\SalesOrder;
use RuntimeException;

/**
 * BR-043: MRP netting — gross (BOM explode) + safety stock − available − on-order = net.
 * BR-045: MRP READ-ONLY — hanya menghasilkan saran; TIDAK auto-PO/PR.
 * BR-120: PR yang dibuat planner dari saran menyimpan mrp_requirement_id.
 */
class MrpService
{
    /**
     * Jalankan MRP untuk SO CONFIRMED terpilih.
     * $params: ['so_ids' => int[], 'horizon_days' => int, 'time_fence_days' => int]
     */
    public function run(int $companyId, array $params, User $user): MrpRun
    {
        $soIds = $params['so_ids'] ?? [];
        if (empty($soIds)) {
            throw new RuntimeException('MRP run memerlukan minimal 1 SO CONFIRMED.');
        }

        return DB::transaction(function () use ($companyId, $params, $soIds, $user): MrpRun {
            $run = MrpRun::create([
                'company_id' => $companyId,
                'run_no' => (int) MrpRun::withoutGlobalScopes()->where('company_id', $companyId)->max('run_no') + 1,
                'params' => $params,
                'status' => 'COMPLETED',
                'created_by' => $user->id,
            ]);

            // 1. GROSS: BOM explode per style dari SO CONFIRMED (hanya BOM APPROVED — BR-023)
            $gross = [];   // material_id => ['qty' => float, 'uom_id' => int, 'need_date' => date|null]

            $orders = SalesOrder::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereIn('id', $soIds)
                ->where('status', 'CONFIRMED')
                ->with('lines')
                ->get();

            if ($orders->isEmpty()) {
                throw new RuntimeException('Tidak ada SO CONFIRMED pada pilihan.');
            }

            foreach ($orders as $so) {
                $qtyPerStyle = $so->lines->groupBy('style_id')->map(fn ($l) => $l->sum(fn ($x) => (float) $x->qty));

                foreach ($qtyPerStyle as $styleId => $qty) {
                    $bom = Bom::where('style_id', $styleId)->first()?->approvedVersion();
                    if ($bom === null) {
                        throw new RuntimeException("Style #{$styleId} tidak punya BOM APPROVED (BR-023).");
                    }

                    foreach ($bom->lines as $bomLine) {
                        $need = $bomLine->grossPerPcs() * $qty;   // qty_per_pcs + wastage + shrinkage (BR-031/032)
                        $key = $bomLine->material_id;

                        if (! isset($gross[$key])) {
                            $gross[$key] = ['qty' => 0.0, 'uom_id' => $bomLine->uom_id, 'need_date' => $so->ex_factory_date];
                        }
                        $gross[$key]['qty'] += $need;
                    }
                }
            }

            // 2. On-order: PO APPROVED/PARTIAL_RECEIVED yang belum diterima penuh
            $onOrder = DB::table('po_lines')
                ->join('purchase_orders', 'purchase_orders.id', '=', 'po_lines.purchase_order_id')
                ->where('purchase_orders.company_id', $companyId)
                ->whereIn('purchase_orders.status', ['APPROVED', 'PARTIAL_RECEIVED'])
                ->whereNull('purchase_orders.deleted_at')
                ->selectRaw('po_lines.material_id, SUM(po_lines.qty - po_lines.received_qty) as on_order')
                ->groupBy('po_lines.material_id')
                ->pluck('on_order', 'po_lines.material_id');

            // 3. Available: Σ saldo (on_hand − reserved − quality_hold) per material — BR-006
            $materialIds = array_keys($gross);
            $balances = StockBalance::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereIn('material_id', $materialIds)
                ->selectRaw('material_id, SUM(on_hand - reserved - quality_hold) as available')
                ->groupBy('material_id')
                ->pluck('available', 'material_id');

            // 4. NETTING + simpan requirements
            foreach ($gross as $materialId => $g) {
                $material = Material::withoutGlobalScopes()->findOrFail($materialId);
                $safety = (float) $material->safety_stock_qty;   // BR-043
                $available = (float) ($balances[$materialId] ?? 0);
                $ordered = (float) ($onOrder[$materialId] ?? 0);

                $net = max(0.0, $g['qty'] + $safety - $available - $ordered);

                MrpRequirement::create([
                    'mrp_run_id' => $run->id,
                    'material_id' => $materialId,
                    'gross_qty' => round($g['qty'], 4),
                    'safety_stock_qty' => $safety,
                    'available_qty' => round($available, 4),
                    'on_order_qty' => round($ordered, 4),
                    'net_qty' => round($net, 4),
                    'uom_id' => $g['uom_id'],
                    'need_date' => $g['need_date'],
                    'converted_to_pr' => false,
                ]);
            }

            return $run->load('requirements.material');
        });
    }

    /**
     * BR-045/120: konversi shortage → PR lines (planner eksplisit memilih; TIDAK otomatis).
     * Mengembalikan payload lines siap untuk PurchasingService::createPr(source=MRP).
     */
    public function toPrLines(array $requirementIds): array
    {
        $requirements = MrpRequirement::whereIn('id', $requirementIds)
            ->where('net_qty', '>', 0)
            ->where('converted_to_pr', false)
            ->get();

        if ($requirements->isEmpty()) {
            throw new RuntimeException('Tidak ada requirement valid (net > 0, belum dikonversi).');
        }

        return $requirements->map(fn ($r) => [
            'material_id' => $r->material_id,
            'qty' => (float) $r->net_qty,
            'uom_id' => $r->uom_id,
            'need_date' => $r->need_date?->toDateString(),
            'mrp_requirement_id' => $r->id,   // BR-120: trace balik ke MRP run
        ])->all();
    }

    /** Tandai requirement sudah dikonversi ke PR (dipanggil setelah PR dibuat). */
    public function markConverted(array $requirementIds): void
    {
        MrpRequirement::whereIn('id', $requirementIds)->update(['converted_to_pr' => true]);
    }
}
