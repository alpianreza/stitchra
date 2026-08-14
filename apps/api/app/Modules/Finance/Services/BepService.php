<?php

namespace Modules\Finance\Services;

use Modules\ProductDev\Models\CostSheet;
use RuntimeException;

/**
 * BR-104 (DEC-2026-08-14-01 — domain Accounting):
 *   BEP qty = Fixed Cost ÷ (harga jual − variable cost per unit)
 * Factory-wide per bulan + per style. Dihitung dari data — tanpa tabel baru.
 */
class BepService
{
    /**
     * BEP dasar. Mengembalikan qty unit (bulat ke atas) + revenue pada titik impas.
     * Harga harus > variable cost — kalau tidak, BEP tidak terdefinisi (margin negatif/nol).
     */
    public function compute(float $fixedCost, float $pricePerUnit, float $variableCostPerUnit): array
    {
        $contribution = $pricePerUnit - $variableCostPerUnit;

        if ($contribution <= 0) {
            throw new RuntimeException(
                "BR-104: harga ({$pricePerUnit}) harus > variable cost ({$variableCostPerUnit}) — BEP tidak terdefinisi."
            );
        }
        if ($fixedCost < 0) {
            throw new RuntimeException('Fixed cost tidak boleh negatif.');
        }

        $bepQty = (int) ceil($fixedCost / $contribution);

        return [
            'bep_qty' => $bepQty,
            'bep_revenue' => round($bepQty * $pricePerUnit, 4),
            'contribution_margin_per_unit' => round($contribution, 4),
            'contribution_margin_ratio' => $pricePerUnit > 0 ? round($contribution / $pricePerUnit, 6) : null,
        ];
    }

    /**
     * BEP per style (BR-104): harga = FOB cost sheet APPROVED;
     * variable cost = fabric + trim + CM + OH + subcon (per pcs).
     */
    public function forStyle(int $companyId, int $styleId, float $fixedCostShare): array
    {
        $sheet = CostSheet::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('style_id', $styleId)->where('status', 'APPROVED')
            ->latest('id')->first();

        if ($sheet === null) {
            throw new RuntimeException("Style #{$styleId} belum punya cost sheet APPROVED (BR-100).");
        }
        if ((float) $sheet->fob_price <= 0) {
            throw new RuntimeException("Cost sheet {$sheet->doc_no} belum punya FOB price.");
        }

        $variable = $sheet->totalManufacturingCost();

        return $this->compute($fixedCostShare, (float) $sheet->fob_price, $variable)
            + ['style_id' => $styleId, 'cost_sheet' => $sheet->doc_no];
    }

    /**
     * BEP factory-wide per bulan: weighted average price & variable cost
     * dari cost sheets APPROVED aktif (bobot = sama rata per style — refinemen: bobot volume).
     */
    public function factoryWide(int $companyId, string $period, float $fixedCost): array
    {
        $sheets = CostSheet::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('status', 'APPROVED')
            ->where('fob_price', '>', 0)
            ->get();

        if ($sheets->isEmpty()) {
            throw new RuntimeException('Belum ada cost sheet APPROVED dengan FOB — BEP factory-wide belum bisa dihitung.');
        }

        $avgPrice = $sheets->avg(fn ($s) => (float) $s->fob_price);
        $avgVariable = $sheets->avg(fn ($s) => $s->totalManufacturingCost());

        return $this->compute($fixedCost, (float) $avgPrice, (float) $avgVariable)
            + ['period' => $period, 'styles_count' => $sheets->count()];
    }
}
