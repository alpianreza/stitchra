<?php

namespace Modules\Cutting\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Cutting\Models\CutOrder;
use Modules\Production\Models\ProductionOrder;
use Modules\Receiving\Models\FabricRoll;
use RuntimeException;

/**
 * Cutting (PF-05): cut order → marker log (konsumsi aktual per roll, BR-031/041)
 * → bundles (BR-061). MO berpindah RELEASED → CUTTING saat cut order pertama.
 */
class CuttingService
{
    public function __construct(
        private NumberingService $numbering,
        private AuditService $audit,
    ) {}

    /** Buat cut order dari MO; lines: colorway_id, size_id, qty_cut. */
    public function create(ProductionOrder $mo, array $lines, User $user): CutOrder
    {
        if (! in_array($mo->status, ['RELEASED', 'CUTTING'], true)) {
            throw new RuntimeException("Cut order hanya untuk MO RELEASED/CUTTING (status: {$mo->status}).");
        }
        if (empty($lines)) {
            throw new RuntimeException('Cut order wajib punya minimal 1 line.');
        }

        return DB::transaction(function () use ($mo, $lines, $user): CutOrder {
            $cutOrder = CutOrder::create([
                'company_id' => $mo->company_id,
                'doc_no' => $this->numbering->next($mo->company_id, 'OUT'),   // prefix CUT
                'production_order_id' => $mo->id,
                'cut_date' => now()->toDateString(),
                'status' => 'IN_PROGRESS',
                'created_by' => $user->id,
            ]);

            foreach ($lines as $line) {
                $cutOrder->lines()->create($line);
            }

            // Transisi MO: RELEASED → CUTTING (BR-012)
            if ($mo->status === 'RELEASED') {
                $mo->update(['status' => 'CUTTING', 'actual_start' => now()->toDateString()]);
            }

            $this->audit->record('create', $cutOrder, after: ['doc_no' => $cutOrder->doc_no, 'mo' => $mo->doc_no]);

            return $cutOrder->load('lines');
        });
    }

    /**
     * Marker log — konsumsi kain AKTUAL per roll (BR-031/041).
     * qty_fabric_used_m mengurangi qty_remaining_meter roll.
     */
    public function recordMarker(CutOrder $cutOrder, array $markers, User $user): CutOrder
    {
        return DB::transaction(function () use ($cutOrder, $markers, $user): CutOrder {
            foreach ($markers as $marker) {
                $roll = FabricRoll::lockForUpdate()->findOrFail($marker['roll_id']);

                if ($roll->status !== 'RELEASED') {
                    throw new RuntimeException("Roll {$roll->roll_no} berstatus {$roll->status} — harus RELEASED (lulus inward QC).");
                }
                if ((float) $roll->qty_remaining_meter < (float) $marker['qty_fabric_used_m']) {
                    throw new RuntimeException("Roll {$roll->roll_no}: pemakaian {$marker['qty_fabric_used_m']}m melebihi sisa {$roll->qty_remaining_meter}m.");
                }

                $cutOrder->markerLogs()->create(array_merge($marker, ['created_by' => $user->id]));
                $roll->consume((float) $marker['qty_fabric_used_m']);   // BR-042: sisa = leftover
            }

            $this->audit->record('update', $cutOrder, after: ['markers' => count($markers)]);

            return $cutOrder->load('markerLogs');
        });
    }

    /**
     * Generate bundles dari cut order line (BR-061).
     * $bundleSize = pcs per bundle; bundle_no = {doc_no}-{line_seq}-{bundle_seq}.
     */
    public function generateBundles(CutOrder $cutOrder, int $cutOrderLineId, int $bundleSize, User $user): array
    {
        return DB::transaction(function () use ($cutOrder, $cutOrderLineId, $bundleSize, $user): array {
            $line = $cutOrder->lines()->findOrFail($cutOrderLineId);

            if ($bundleSize <= 0) {
                throw new RuntimeException('Bundle size harus > 0.');
            }
            if ($line->bundles()->exists()) {
                throw new RuntimeException('Bundles untuk line ini sudah digenerate.');
            }

            $remaining = (float) $line->qty_cut;
            $seq = 0;
            $bundles = [];

            while ($remaining > 0) {
                $seq++;
                $qty = min($bundleSize, $remaining);
                $bundles[] = $line->bundles()->create([
                    'company_id' => $cutOrder->company_id,
                    'bundle_no' => $cutOrder->doc_no.'-L'.$line->id.'-B'.str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
                    'production_order_id' => $cutOrder->production_order_id,
                    'qty' => $qty,
                    'current_stage' => 'CUTTING',
                    'status' => 'ACTIVE',
                ]);
                $remaining -= $qty;
            }

            return $bundles;
        });
    }

    /** Selesaikan cut order; update consumption_actual di BOM (BR-031). */
    public function complete(CutOrder $cutOrder, User $user): CutOrder
    {
        return DB::transaction(function () use ($cutOrder, $user): CutOrder {
            $mo = $cutOrder->productionOrder;

            // BR-031: consumption aktual = total meter dipakai / qty cut
            $totalUsed = (float) $cutOrder->markerLogs()->sum('qty_fabric_used_m');
            $totalCut = (float) $cutOrder->lines()->sum('qty_cut');

            if ($totalUsed > 0 && $totalCut > 0) {
                $actualPerPcs = round($totalUsed / $totalCut, 6);

                // Update BOM line fabric di versi snapshot MO
                $mo->bomVersion->lines()
                    ->whereHas('material', fn ($q) => $q->where('type', 'FABRIC'))
                    ->update(['consumption_actual' => $actualPerPcs]);
            }

            $cutOrder->update(['status' => 'COMPLETED', 'updated_by' => $user->id]);

            $this->audit->record('update', $cutOrder, after: ['status' => 'COMPLETED', 'consumption_actual' => $actualPerPcs ?? null]);

            return $cutOrder->fresh(['lines', 'markerLogs']);
        });
    }
}
