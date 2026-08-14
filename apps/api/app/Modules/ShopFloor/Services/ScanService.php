<?php

namespace Modules\ShopFloor\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Cutting\Models\Bundle;
use Modules\ShopFloor\Models\ProductionScan;
use RuntimeException;

/**
 * BR-062: scan IN/OUT bundle per operasi = bukti kehadiran fisik.
 * BR-063: WIP = bundle × stage (dari scan, bukan input manual).
 * Validasi urutan: OUT butuh IN; IN operasi N+1 butuh OUT operasi N (sesuai routing MO).
 */
class ScanService
{
    /**
     * Catat scan. $bundleNo dari scanner (keyboard-wedge) atau input manual.
     * $data: operation_id, direction (IN/OUT), stage (SEWING/FINISHING), line_id?, employee_id?
     */
    public function scan(int $companyId, string $bundleNo, array $data, User $user): ProductionScan
    {
        return DB::transaction(function () use ($companyId, $bundleNo, $data, $user): ProductionScan {
            $bundle = Bundle::where('company_id', $companyId)
                ->where('bundle_no', $bundleNo)
                ->where('status', 'ACTIVE')
                ->firstOrFail();

            $mo = $bundle->productionOrder;
            $direction = $data['direction'];
            $operationId = (int) $data['operation_id'];
            $stage = $data['stage'];

            // Operasi harus bagian dari routing MO (snapshot — BR-030)
            $routingOps = $mo->routingVersion->operations()->orderBy('seq')->get();
            $routingOp = $routingOps->firstWhere('operation_id', $operationId);
            if ($routingOp === null) {
                throw new RuntimeException("Operasi #{$operationId} tidak ada di routing MO {$mo->doc_no}.");
            }

            $lastScan = ProductionScan::where('bundle_id', $bundle->id)
                ->where('operation_id', $operationId)
                ->orderByDesc('scanned_at')
                ->first();

            if ($direction === 'OUT') {
                // OUT butuh IN terakhir yang belum di-OUT
                if ($lastScan === null || $lastScan->direction !== 'IN') {
                    throw new RuntimeException("Bundle {$bundleNo}: OUT tanpa IN pada operasi ini.");
                }
            } else {
                // IN: bundle tidak boleh sedang "di dalam" operasi yang sama (double IN)
                if ($lastScan !== null && $lastScan->direction === 'IN') {
                    throw new RuntimeException("Bundle {$bundleNo}: double IN pada operasi yang sama.");
                }
                // Urutan routing: operasi pertama boleh langsung IN; selanjutnya butuh OUT dari operasi sebelumnya
                $prevOp = $routingOps->where('seq', '<', $routingOp->seq)->sortByDesc('seq')->first();
                if ($prevOp !== null) {
                    $prevOut = ProductionScan::where('bundle_id', $bundle->id)
                        ->where('operation_id', $prevOp->operation_id)
                        ->where('direction', 'OUT')
                        ->exists();
                    if (! $prevOut) {
                        throw new RuntimeException("Bundle {$bundleNo}: belum selesai operasi sebelumnya (seq {$prevOp->seq}).");
                    }
                }
            }

            $scan = ProductionScan::create([
                'company_id' => $companyId,
                'bundle_id' => $bundle->id,
                'operation_id' => $operationId,
                'production_order_id' => $mo->id,
                'line_id' => $data['line_id'] ?? $mo->line_id,
                'employee_id' => $data['employee_id'] ?? null,
                'direction' => $direction,
                'stage' => $stage,
                'scanned_at' => now(),
            ]);

            // Transisi stage bundle & MO (BR-012/063)
            $bundle->update(['current_stage' => $stage]);
            $this->advanceMoStatus($mo, $stage);

            return $scan;
        });
    }

    /** WIP per MO per stage (BR-063) — agregasi dari scan, tanpa tabel agregat (anti double-count). */
    public function wipByStage(int $moId): array
    {
        return Bundle::where('production_order_id', $moId)
            ->where('status', 'ACTIVE')
            ->selectRaw('current_stage, COUNT(*) as bundle_count, SUM(qty) as pcs')
            ->groupBy('current_stage')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->current_stage => ['bundles' => (int) $r->bundle_count, 'pcs' => (float) $r->pcs]])
            ->all();
    }

    /** Daily output per line (scan OUT per tanggal) */
    public function dailyOutput(int $lineId, string $date): array
    {
        return ProductionScan::where('line_id', $lineId)
            ->where('direction', 'OUT')
            ->whereDate('scanned_at', $date)
            ->selectRaw('stage, operation_id, SUM(bundles.qty) as pcs, COUNT(DISTINCT bundle_id) as bundles')
            ->join('bundles', 'bundles.id', '=', 'production_scans.bundle_id')
            ->groupBy('stage', 'operation_id')
            ->get()
            ->all();
    }

    /** Transisi status MO sesuai stage (BR-012) — maju saja, tidak mundur. */
    private function advanceMoStatus($mo, string $stage): void
    {
        $target = match ($stage) {
            'SEWING' => 'SEWING',
            'FINISHING' => 'FINISHING',
            default => null,
        };

        if ($target === null) {
            return;
        }

        $order = ['PLANNED' => 0, 'RELEASED' => 1, 'CUTTING' => 2, 'SEWING' => 3, 'FINISHING' => 4, 'QC' => 5, 'PACKED' => 6, 'CLOSED' => 7];

        if (($order[$target] ?? 0) > ($order[$mo->status] ?? 0)) {
            $mo->update(['status' => $target]);
        }
    }
}
