<?php

namespace Modules\ProductDev\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Approval\ApprovalEngine;
use Modules\Core\Models\User;
use Modules\Core\Services\NumberingService;
use Modules\MasterData\Models\Line;
use Modules\MasterData\Models\LineCostRate;
use Modules\MasterData\Models\OverheadRate;
use Modules\MasterData\Models\Style;
use Modules\ProductDev\Models\CostSheet;
use RuntimeException;

class CostingService
{
    public function __construct(
        private BomService $boms,
        private RoutingService $routings,
        private NumberingService $numbering,
        private ApprovalEngine $approval,
    ) {}

    public function compute(
        int $styleId,
        int $companyId,
        array $materialPrices,
        int $lineId,
        string $period,
        User $creator,
    ): CostSheet {
        if (! Style::query()->where('company_id', $companyId)->whereKey($styleId)->exists()
            || ! Line::query()->where('company_id', $companyId)->whereKey($lineId)->exists()) {
            throw new RuntimeException('Style/line costing tidak ditemukan pada company aktif.');
        }

        $bom = $this->boms->activeVersion($styleId);
        $routing = $this->routings->activeVersion($styleId);
        if ($bom === null || $routing === null) {
            throw new RuntimeException('BR-023/BR-100: style belum punya BOM & Routing APPROVED.');
        }

        $lineRate = LineCostRate::query()->where('company_id', $companyId)
            ->where('line_id', $lineId)->where('period', $period)->value('cost_per_minute');
        $ohRate = OverheadRate::query()->where('company_id', $companyId)
            ->where('period', $period)->value('rate_per_minute');

        if ($lineRate === null || (float) $lineRate <= 0 || $ohRate === null || (float) $ohRate <= 0) {
            throw new RuntimeException('BR-100: line cost rate dan overhead rate wajib tersedia dan lebih besar dari nol.');
        }

        return DB::transaction(function () use ($styleId, $companyId, $materialPrices, $period, $creator, $bom, $routing, $lineRate, $ohRate): CostSheet {
            Style::withoutGlobalScopes()->where('company_id', $companyId)->whereKey($styleId)->lockForUpdate()->firstOrFail();

            $fabricCost = 0.0;
            $trimCost = 0.0;
            $linesPayload = [];

            foreach ($bom->lines as $line) {
                $material = $line->material;
                if ($material === null || (int) $material->company_id !== $companyId) {
                    throw new RuntimeException('Material BOM costing tidak tersedia pada company aktif.');
                }
                if (! array_key_exists($line->material_id, $materialPrices) || (float) $materialPrices[$line->material_id] <= 0) {
                    throw new RuntimeException("BR-100: harga material #{$line->material_id} wajib tersedia dan lebih besar dari nol.");
                }

                $qty = $line->grossPerPcs();
                $rate = (float) $materialPrices[$line->material_id];
                $amount = round($qty * $rate, 4);
                $isFabric = $material->isFabric();
                $isFabric ? $fabricCost += $amount : $trimCost += $amount;

                $linesPayload[] = [
                    'component_type' => $isFabric ? 'FABRIC' : 'TRIM',
                    'description' => $material->name,
                    'qty' => $qty,
                    'rate' => $rate,
                    'amount' => $amount,
                ];
            }

            $totalSam = (float) $routing->total_sam;
            if ($totalSam <= 0) {
                throw new RuntimeException('BR-100: total SAM routing harus lebih besar dari nol.');
            }

            $cmCost = round($totalSam * (float) $lineRate, 4);
            $ohCost = round($totalSam * (float) $ohRate, 4);
            $version = (int) CostSheet::withoutGlobalScopes()
                ->where('company_id', $companyId)->where('style_id', $styleId)->max('version') + 1;

            $sheet = CostSheet::create([
                'company_id' => $companyId,
                'doc_no' => $this->numbering->next($companyId, 'COST'),
                'style_id' => $styleId,
                'bom_version_id' => $bom->id,
                'routing_version_id' => $routing->id,
                'version' => $version,
                'fabric_cost' => $fabricCost,
                'trim_cost' => $trimCost,
                'cm_cost' => $cmCost,
                'overhead_cost' => $ohCost,
                'status' => 'DRAFT',
                'created_by' => $creator->id,
            ]);

            foreach ($linesPayload as $payload) {
                $sheet->lines()->create($payload);
            }
            $sheet->lines()->create(['component_type' => 'CM', 'description' => "Cut-Make (SAM {$totalSam} × rate)", 'qty' => $totalSam, 'rate' => $lineRate, 'amount' => $cmCost]);
            $sheet->lines()->create(['component_type' => 'OVERHEAD', 'description' => "Overhead (SAM {$totalSam} × OH rate)", 'qty' => $totalSam, 'rate' => $ohRate, 'amount' => $ohCost]);

            return $sheet->load('lines');
        });
    }

    public function setPrice(CostSheet $sheet, float $fobPrice): CostSheet
    {
        if ($sheet->status !== 'DRAFT') {
            throw new RuntimeException('Hanya cost sheet DRAFT yang bisa diubah harganya.');
        }

        $total = $sheet->totalManufacturingCost();
        if ($fobPrice < $total) {
            throw new RuntimeException("FOB ({$fobPrice}) di bawah total manufacturing cost ({$total}).");
        }

        $sheet->update([
            'fob_price' => $fobPrice,
            'margin_pct' => $total > 0 ? round(($fobPrice - $total) / $total * 100, 4) : 0,
        ]);

        return $sheet->fresh();
    }

    public function submit(CostSheet $sheet, User $submitter): void
    {
        DB::transaction(function () use ($sheet, $submitter): void {
            $locked = CostSheet::withoutGlobalScopes()->whereKey($sheet->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'DRAFT') {
                throw new RuntimeException('Hanya cost sheet DRAFT yang bisa disubmit.');
            }
            if ((float) $locked->fob_price <= 0) {
                throw new RuntimeException('FOB price wajib ditetapkan sebelum cost sheet disubmit.');
            }

            $locked->update(['status' => 'SUBMITTED']);
            $this->approval->submit($locked, 'COST', $submitter);
        });
    }

    public function markApproved(CostSheet $sheet): void
    {
        DB::transaction(function () use ($sheet): void {
            $locked = CostSheet::withoutGlobalScopes()->whereKey($sheet->id)->lockForUpdate()->firstOrFail();
            CostSheet::withoutGlobalScopes()
                ->where('company_id', $locked->company_id)->where('style_id', $locked->style_id)
                ->where('id', '!=', $locked->id)->where('status', 'APPROVED')
                ->update(['status' => 'OBSOLETE']);
            $locked->update(['status' => 'APPROVED']);
        });
    }
}
