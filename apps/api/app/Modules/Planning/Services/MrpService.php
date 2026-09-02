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

class MrpService
{
    public function run(int $companyId, array $params, User $user): MrpRun
    {
        $soIds = array_values(array_unique(array_map('intval', $params['so_ids'] ?? [])));
        if ($soIds === []) throw new RuntimeException('MRP run memerlukan minimal 1 SO CONFIRMED.');
        $this->assertUserCompany($user, $companyId);

        return DB::transaction(function () use ($companyId, $params, $soIds, $user): MrpRun {
            DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();
            $orders = SalesOrder::withoutGlobalScopes()->where('company_id', $companyId)
                ->whereIn('id', $soIds)->where('status', 'CONFIRMED')->with('lines')->get();
            if ($orders->count() !== count($soIds)) {
                throw new RuntimeException('Seluruh SO pilihan harus CONFIRMED dan berasal dari company aktif.');
            }

            $run = MrpRun::create([
                'company_id' => $companyId,
                'run_no' => (int) MrpRun::withoutGlobalScopes()->where('company_id', $companyId)->max('run_no') + 1,
                'params' => array_merge($params, ['so_ids' => $soIds]),
                'status' => 'COMPLETED',
                'created_by' => $user->id,
            ]);

            $gross = [];
            $trace = [];   // BR-121: per material — kontribusi gross per SO line × BOM line
            foreach ($orders as $so) {
                foreach ($so->lines->groupBy('style_id') as $styleId => $soLines) {
                    $bom = Bom::where('style_id', $styleId)->first()?->approvedVersion();
                    if ($bom === null) throw new RuntimeException("Style #{$styleId} tidak punya BOM APPROVED (BR-023).");
                    foreach ($bom->lines as $bomLine) {
                        $materialId = (int) $bomLine->material_id;
                        if (! isset($gross[$materialId])) {
                            $gross[$materialId] = ['qty' => 0.0, 'uom_id' => $bomLine->uom_id, 'need_date' => $so->ex_factory_date];
                        } elseif ((int) $gross[$materialId]['uom_id'] !== (int) $bomLine->uom_id) {
                            throw new RuntimeException("Material #{$materialId} memiliki UOM BOM yang tidak konsisten.");
                        }
                        $perPcs = $bomLine->grossPerPcs();
                        foreach ($soLines as $soLine) {
                            $qty = round($perPcs * (float) $soLine->qty, 4);
                            if ($qty <= 0) continue;
                            $gross[$materialId]['qty'] += $qty;
                            // BR-121: jejak "kenapa butuh N?" — SO line × BOM line
                            $trace[$materialId][] = [
                                'sales_order_line_id' => (int) $soLine->id,
                                'bom_line_id' => (int) $bomLine->id,
                                'gross_qty' => $qty,
                            ];
                        }
                        if ($so->ex_factory_date && (! $gross[$materialId]['need_date'] || $so->ex_factory_date->lt($gross[$materialId]['need_date']))) {
                            $gross[$materialId]['need_date'] = $so->ex_factory_date;
                        }
                    }
                }
            }

            $onOrder = DB::table('po_lines')
                ->join('purchase_orders', 'purchase_orders.id', '=', 'po_lines.purchase_order_id')
                ->where('purchase_orders.company_id', $companyId)
                ->whereIn('purchase_orders.status', ['APPROVED', 'PARTIAL_RECEIVED'])
                ->selectRaw('po_lines.material_id, SUM(GREATEST(po_lines.qty - po_lines.received_qty, 0)) as on_order')
                ->groupBy('po_lines.material_id')->pluck('on_order', 'po_lines.material_id');

            $materialIds = array_keys($gross);
            $balances = StockBalance::withoutGlobalScopes()->where('company_id', $companyId)
                ->whereIn('material_id', $materialIds)
                ->selectRaw('material_id, SUM(on_hand - reserved - quality_hold) as available')
                ->groupBy('material_id')->pluck('available', 'material_id');

            foreach ($gross as $materialId => $data) {
                $material = Material::withoutGlobalScopes()->where('company_id', $companyId)->whereKey($materialId)->first();
                if ($material === null) throw new RuntimeException("Material #{$materialId} tidak berasal dari company aktif.");
                $safety = (float) $material->safety_stock_qty;
                $available = max(0.0, (float) ($balances[$materialId] ?? 0));
                $ordered = max(0.0, (float) ($onOrder[$materialId] ?? 0));
                $net = max(0.0, $data['qty'] + $safety - $available - $ordered);
                $requirement = MrpRequirement::create([
                    'mrp_run_id' => $run->id, 'material_id' => $materialId,
                    'gross_qty' => round($data['qty'], 4), 'safety_stock_qty' => $safety,
                    'available_qty' => round($available, 4), 'on_order_qty' => round($ordered, 4),
                    'net_qty' => round($net, 4), 'uom_id' => $data['uom_id'],
                    'need_date' => $data['need_date'], 'converted_to_pr' => false,
                ]);

                // BR-121: persist trace — Σ kontribusi trace == gross_qty requirement
                foreach ($trace[$materialId] ?? [] as $row) {
                    $requirement->traceLines()->create($row);
                }
            }
            return $run->load('requirements.material');
        });
    }

    public function toPrLines(array $requirementIds, ?MrpRun $run = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $requirementIds)));
        if ($ids === []) throw new RuntimeException('Requirement wajib dipilih.');
        $run ??= MrpRun::withoutGlobalScopes()->whereHas('requirements', fn ($query) => $query->whereKey($ids[0]))->firstOrFail();
        $requirements = MrpRequirement::query()->where('mrp_run_id', $run->id)
            ->whereIn('id', $ids)->where('net_qty', '>', 0)->where('converted_to_pr', false)
            ->lockForUpdate()->get();
        if ($requirements->count() !== count($ids)) {
            throw new RuntimeException('Seluruh requirement harus berasal dari run yang sama, net > 0, dan belum dikonversi.');
        }
        return $requirements->map(fn ($requirement) => [
            'material_id' => $requirement->material_id, 'qty' => (float) $requirement->net_qty,
            'uom_id' => $requirement->uom_id, 'need_date' => $requirement->need_date?->toDateString(),
            'mrp_requirement_id' => $requirement->id,
        ])->all();
    }

    public function markConverted(array $requirementIds, ?MrpRun $run = null): void
    {
        $ids = array_values(array_unique(array_map('intval', $requirementIds)));
        $run ??= MrpRun::withoutGlobalScopes()->whereHas('requirements', fn ($query) => $query->whereKey($ids[0] ?? 0))->firstOrFail();
        $updated = MrpRequirement::query()->where('mrp_run_id', $run->id)
            ->whereIn('id', $ids)->where('converted_to_pr', false)->update(['converted_to_pr' => true]);
        if ($updated !== count($ids)) throw new RuntimeException('Requirement conversion tidak konsisten atau sudah diproses.');
    }

    private function assertUserCompany(User $user, int $companyId): void
    {
        if ((int) $user->company_id !== $companyId && ! $user->companies()->whereKey($companyId)->exists()) {
            throw new RuntimeException('User tidak memiliki akses ke company MRP run.');
        }
    }
}
