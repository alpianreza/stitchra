<?php

namespace Modules\ProductDev\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Approval\ApprovalEngine;
use Modules\Core\Models\User;
use Modules\Core\Services\NumberingService;
use Modules\MasterData\Models\LineCostRate;
use Modules\MasterData\Models\OverheadRate;
use Modules\ProductDev\Models\CostSheet;
use RuntimeException;

/**
 * BR-100: Pre-production cost sheet (estimated) per style.
 * FOB = Fabric + Trim + CM + Overhead (+ Subcon est) ; CM = total SAM × cost-per-minute;
 * Overhead = total SAM × OH rate per menit (BR-009).
 * Cost sheet APPROVED menjadi standard cost untuk variance.
 */
class CostingService
{
    public function __construct(
        private BomService $boms,
        private RoutingService $routings,
        private NumberingService $numbering,
        private ApprovalEngine $approval,
    ) {}

    /**
     * Hitung cost sheet dari BOM + Routing APPROVED.
     * $materialPrices: map material_id → harga per UOM pakai (dari quotation/PO terakhir;
     * diisi caller — Phase 4 akan mengambil otomatis dari PO terakhir).
     */
    public function compute(
        int $styleId,
        int $companyId,
        array $materialPrices,
        int $lineId,
        string $period,
        User $creator,
    ): CostSheet {
        $bom = $this->boms->activeVersion($styleId);
        $routing = $this->routings->activeVersion($styleId);

        if ($bom === null || $routing === null) {
            throw new RuntimeException('BR-023/BR-100: style belum punya BOM & Routing APPROVED.');
        }

        return DB::transaction(function () use ($styleId, $companyId, $materialPrices, $lineId, $period, $creator, $bom, $routing): CostSheet {
            $fabricCost = 0.0;
            $trimCost = 0.0;
            $linesPayload = [];

            foreach ($bom->lines as $line) {
                $qty = $line->grossPerPcs();
                $rate = (float) ($materialPrices[$line->material_id] ?? 0);
                $amount = round($qty * $rate, 4);
                $isFabric = $line->material->isFabric();

                if ($isFabric) {
                    $fabricCost += $amount;
                } else {
                    $trimCost += $amount;
                }

                $linesPayload[] = [
                    'component_type' => $isFabric ? 'FABRIC' : 'TRIM',
                    'description' => $line->material->name,
                    'qty' => $qty,
                    'rate' => $rate,
                    'amount' => $amount,
                ];
            }

            $totalSam = (float) $routing->total_sam;

            $lineRate = LineCostRate::withoutGlobalScopes()
                ->where('company_id', $companyId)->where('line_id', $lineId)->where('period', $period)
                ->value('cost_per_minute');
            $ohRate = OverheadRate::withoutGlobalScopes()
                ->where('company_id', $companyId)->where('period', $period)
                ->value('rate_per_minute');

            $cmCost = round($totalSam * (float) ($lineRate ?? 0), 4);
            $ohCost = round($totalSam * (float) ($ohRate ?? 0), 4);

            $sheet = CostSheet::create([
                'company_id' => $companyId,
                'doc_no' => $this->numbering->next($companyId, 'COST'),
                'style_id' => $styleId,
                'bom_version_id' => $bom->id,
                'routing_version_id' => $routing->id,
                'version' => (int) CostSheet::withoutGlobalScopes()->where('company_id', $companyId)->where('style_id', $styleId)->max('version') + 1,
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
            $sheet->lines()->create(['component_type' => 'CM', 'description' => "Cut-Make (SAM {$totalSam} × rate)", 'qty' => $totalSam, 'rate' => $lineRate ?? 0, 'amount' => $cmCost]);
            $sheet->lines()->create(['component_type' => 'OVERHEAD', 'description' => "Overhead (SAM {$totalSam} × OH rate)", 'qty' => $totalSam, 'rate' => $ohRate ?? 0, 'amount' => $ohCost]);

            return $sheet->load('lines');
        });
    }

    /** Set FOB + margin; FOB ≥ total cost divalidasi. */
    public function setPrice(CostSheet $sheet, float $fobPrice): CostSheet
    {
        if ($sheet->status !== 'DRAFT') {
            throw new RuntimeException('Hanya cost sheet DRAFT yang bisa diubah harganya.');
        }

        $total = $sheet->totalManufacturingCost();
        if ($fobPrice < $total) {
            throw new RuntimeException("FOB ({$fobPrice}) di bawah total manufacturing cost ({$total}).");
        }

        $margin = $total > 0 ? round(($fobPrice - $total) / $total * 100, 4) : 0;

        $sheet->update(['fob_price' => $fobPrice, 'margin_pct' => $margin]);

        return $sheet->fresh();
    }

    public function submit(CostSheet $sheet, User $submitter): void
    {
        if ($sheet->status !== 'DRAFT') {
            throw new RuntimeException('Hanya cost sheet DRAFT yang bisa disubmit.');
        }

        $sheet->update(['status' => 'SUBMITTED']);
        $this->approval->submit($sheet, 'COST', $submitter);
    }

    /** Setelah APPROVED → menjadi standard cost (snapshot untuk variance). */
    public function markApproved(CostSheet $sheet): void
    {
        DB::transaction(function () use ($sheet): void {
            CostSheet::withoutGlobalScopes()
                ->where('company_id', $sheet->company_id)
                ->where('style_id', $sheet->style_id)
                ->where('id', '!=', $sheet->id)
                ->where('status', 'APPROVED')
                ->update(['status' => 'OBSOLETE']);

            $sheet->update(['status' => 'APPROVED']);
        });
    }
}
