<?php

namespace Modules\Planning\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Cutting\Models\CutOrder;
use Modules\Cutting\Services\CuttingService;
use Modules\Planning\Models\CutPlan;
use Modules\Production\Models\ProductionOrder;
use RuntimeException;

class CutPlanService
{
    public function __construct(
        private NumberingService $numbering,
        private AuditService $audit,
        private CuttingService $cutting,
    ) {}

    public function create(ProductionOrder $mo, array $lays, User $user): CutPlan
    {
        return DB::transaction(function () use ($mo, $lays, $user): CutPlan {
            if ($lays === []) throw new RuntimeException('Cut Plan wajib memiliki minimal satu planned lay.');
            $locked = ProductionOrder::withoutGlobalScopes()->with(['matrixLines', 'salesOrder.lines'])
                ->whereKey($mo->id)->lockForUpdate()->firstOrFail();
            $this->access($user, (int) $locked->company_id);
            if ($locked->status !== 'RELEASED') {
                throw new RuntimeException('Cut Plan baru hanya dapat dibuat untuk MO RELEASED sebelum cutting dimulai.');
            }

            [$matrix, $matrixSource] = $this->moMatrix($locked);
            $existing = $this->plannedMatrix((int) $locked->id);
            $planned = [];
            $normalized = [];
            $total = 0.0;

            foreach (array_values($lays) as $index => $lay) {
                $colorwayId = (int) ($lay['colorway_id'] ?? 0);
                $layers = (int) ($lay['layer_count'] ?? 0);
                if ($layers < 1) throw new RuntimeException('Layer count setiap planned lay wajib minimal satu.');
                $ratios = $lay['ratios'] ?? [];
                if ($ratios === []) throw new RuntimeException('Setiap planned lay wajib memiliki minimal satu size ratio.');
                $seenSizes = [];
                $normalizedRatios = [];
                foreach ($ratios as $ratio) {
                    $sizeId = (int) ($ratio['size_id'] ?? 0);
                    $ratioQty = (float) ($ratio['ratio_qty'] ?? 0);
                    if ($ratioQty <= 0) throw new RuntimeException('Size ratio wajib lebih besar dari nol.');
                    if (isset($seenSizes[$sizeId])) throw new RuntimeException('Size ratio dalam planned lay tidak boleh duplikat.');
                    $seenSizes[$sizeId] = true;
                    $key = $colorwayId.':'.$sizeId;
                    if (! isset($matrix[$key])) throw new RuntimeException('Colorway dan size planned lay tidak terdapat pada matrix MO.');
                    $qty = round($layers * $ratioQty, 4);
                    $planned[$key] = round(($planned[$key] ?? 0) + $qty, 4);
                    $total = round($total + $qty, 4);
                    $normalizedRatios[] = ['size_id' => $sizeId, 'ratio_qty' => $ratioQty];
                }
                $markerLength = $lay['estimated_marker_length_m'] ?? null;
                if ($markerLength !== null && (float) $markerLength <= 0) throw new RuntimeException('Estimated marker length harus lebih besar dari nol.');
                $normalized[] = [
                    'lay_sequence' => $index + 1,
                    'colorway_id' => $colorwayId,
                    'layer_count' => $layers,
                    'estimated_marker_length_m' => $markerLength,
                    'ratios' => $normalizedRatios,
                ];
            }

            foreach ($planned as $key => $qty) {
                if (($existing[$key] ?? 0) + $qty - $matrix[$key] > 0.0001) {
                    throw new RuntimeException('Cumulative Cut Plan melebihi matrix MO untuk colorway/size '.$key.'.');
                }
            }
            if ($total <= 0) throw new RuntimeException('Total quantity Cut Plan wajib lebih besar dari nol.');

            $plan = CutPlan::create([
                'company_id' => $locked->company_id,
                'doc_no' => $this->numbering->next((int) $locked->company_id, 'CUT'),
                'production_order_id' => $locked->id,
                'planned_lay_count' => count($normalized),
                'total_qty' => $total,
                'created_by' => $user->id,
            ]);
            foreach ($normalized as $layData) {
                $ratios = $layData['ratios'];
                unset($layData['ratios']);
                $plannedLay = $plan->lays()->create($layData + [
                    'company_id' => $locked->company_id,
                    'created_by' => $user->id,
                ]);
                foreach ($ratios as $ratio) {
                    $plannedLay->ratios()->create($ratio + [
                        'company_id' => $locked->company_id,
                        'created_by' => $user->id,
                    ]);
                }
            }
            $this->audit->record('create', $plan, after: [
                'doc_no' => $plan->doc_no,
                'production_order_id' => $locked->id,
                'planned_lay_count' => count($normalized),
                'total_qty' => $total,
                'matrix_source' => $matrixSource,
                'numbering_source' => 'EXISTING_CUT_COUNTER',
            ]);

            return $plan->fresh(['productionOrder.style', 'lays.colorway.color', 'lays.ratios.size']);
        });
    }

    public function createCutOrder(CutPlan $plan, User $user): CutOrder
    {
        return DB::transaction(function () use ($plan, $user): CutOrder {
            $locked = CutPlan::withoutGlobalScopes()->with(['productionOrder', 'lays.ratios'])
                ->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $this->access($user, (int) $locked->company_id);
            if (CutOrder::withoutGlobalScopes()->where('cut_plan_id', $locked->id)->where('status', '<>', 'CANCELLED')->exists()) {
                throw new RuntimeException('Cut Plan sudah memiliki Cut Order aktif.');
            }
            $lines = [];
            foreach ($this->matrixFromLays($locked) as $key => $qty) {
                [$colorwayId, $sizeId] = array_map('intval', explode(':', $key));
                $lines[] = ['colorway_id' => $colorwayId, 'size_id' => $sizeId, 'qty_cut' => $qty];
            }
            if ($lines === []) throw new RuntimeException('Cut Plan tidak memiliki matrix quantity.');
            return $this->cutting->create($locked->productionOrder, $lines, $user, (int) $locked->id);
        });
    }

    private function moMatrix(ProductionOrder $mo): array
    {
        $matrix = [];
        if ($mo->matrixLines->isNotEmpty()) {
            foreach ($mo->matrixLines as $line) $matrix[$line->colorway_id.':'.$line->size_id] = (float) $line->qty_planned;
            return [$matrix, 'MO_LINES'];
        }
        foreach ($mo->salesOrder->lines->where('style_id', $mo->style_id) as $line) {
            $matrix[$line->colorway_id.':'.$line->size_id] = (float) $line->qty;
        }
        return [$matrix, 'LEGACY_SO_LINES'];
    }

    private function plannedMatrix(int $moId): array
    {
        $result = [];
        $plans = CutPlan::withoutGlobalScopes()->with('lays.ratios')->where('production_order_id', $moId)->get();
        foreach ($plans as $plan) {
            foreach ($this->matrixFromLays($plan) as $key => $qty) $result[$key] = round(($result[$key] ?? 0) + $qty, 4);
        }
        return $result;
    }

    private function matrixFromLays(CutPlan $plan): array
    {
        $result = [];
        foreach ($plan->lays as $lay) {
            foreach ($lay->ratios as $ratio) {
                $key = $lay->colorway_id.':'.$ratio->size_id;
                $result[$key] = round(($result[$key] ?? 0) + ((float) $lay->layer_count * (float) $ratio->ratio_qty), 4);
            }
        }
        return $result;
    }

    private function access(User $user, int $companyId): void
    {
        if ((int) $user->company_id !== $companyId && ! $user->companies()->whereKey($companyId)->exists()) {
            throw new RuntimeException('User tidak memiliki akses ke company Cut Plan.');
        }
    }
}
