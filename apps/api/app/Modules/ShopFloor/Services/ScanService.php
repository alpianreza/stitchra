<?php

namespace Modules\ShopFloor\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Cutting\Models\Bundle;
use Modules\Production\Models\ProductionOrder;
use Modules\ShopFloor\Models\ProductionScan;
use RuntimeException;

class ScanService
{
    public function scan(int $companyId, string $bundleNo, array $data, User $user): ProductionScan
    {
        return DB::transaction(function () use ($companyId, $bundleNo, $data, $user): ProductionScan {
            $this->assertUserCompany($user, $companyId);
            $direction = strtoupper((string) ($data['direction'] ?? ''));
            $stage = strtoupper((string) ($data['stage'] ?? ''));
            if (! in_array($direction, ProductionScan::DIRECTIONS, true) || ! in_array($stage, ProductionScan::STAGES, true)) {
                throw new RuntimeException('Direction atau stage scan tidak valid.');
            }

            $bundle = Bundle::withoutGlobalScopes()->where('company_id', $companyId)
                ->where('bundle_no', $bundleNo)->where('status', 'ACTIVE')->lockForUpdate()->first();
            if ($bundle === null) throw new RuntimeException('Bundle aktif tidak ditemukan pada company ini.');
            $mo = ProductionOrder::withoutGlobalScopes()->where('company_id', $companyId)
                ->whereKey($bundle->production_order_id)->lockForUpdate()->firstOrFail();
            if (! in_array($mo->status, ['CUTTING','SEWING','FINISHING'], true)) {
                throw new RuntimeException("Status MO {$mo->status} tidak mengizinkan scan shop floor.");
            }

            $operationId = (int) ($data['operation_id'] ?? 0);
            $routingOps = $mo->routingVersion->operations()->orderBy('seq')->get();
            $routingOp = $routingOps->firstWhere('operation_id', $operationId);
            if ($routingOp === null) throw new RuntimeException("Operasi #{$operationId} tidak ada di routing snapshot MO.");

            $lineId = $data['line_id'] ?? $mo->line_id;
            if ($lineId !== null && ! DB::table('lines')->where('company_id', $companyId)->where('id', $lineId)->exists()) {
                throw new RuntimeException('Line scan tidak ditemukan pada company ini.');
            }
            $employeeId = $data['employee_id'] ?? null;
            if ($employeeId !== null && ! DB::table('employees')->where('company_id', $companyId)->where('id', $employeeId)->exists()) {
                throw new RuntimeException('Employee scan tidak ditemukan pada company ini.');
            }

            $existingDirection = ProductionScan::withoutGlobalScopes()
                ->where('bundle_id', $bundle->id)->where('operation_id', $operationId)
                ->where('stage', $stage)->where('direction', $direction)->lockForUpdate()->first();
            if ($existingDirection !== null) {
                throw new RuntimeException("Bundle {$bundleNo}: duplicate {$direction} pada operasi dan stage yang sama.");
            }
            $lastScan = ProductionScan::withoutGlobalScopes()->where('bundle_id', $bundle->id)
                ->where('operation_id', $operationId)->where('stage', $stage)
                ->orderByDesc('scanned_at')->orderByDesc('id')->lockForUpdate()->first();

            if ($direction === 'OUT') {
                if ($lastScan === null || $lastScan->direction !== 'IN') {
                    throw new RuntimeException("Bundle {$bundleNo}: OUT tanpa IN pada operasi ini.");
                }
            } else {
                if ($lastScan !== null) throw new RuntimeException("Bundle {$bundleNo}: operasi/stage ini sudah pernah dimulai.");
                if ($stage === 'SEWING') {
                    $prevOp = $routingOps->where('seq', '<', $routingOp->seq)->sortByDesc('seq')->first();
                    if ($prevOp !== null && ! ProductionScan::withoutGlobalScopes()
                        ->where('bundle_id', $bundle->id)->where('operation_id', $prevOp->operation_id)
                        ->where('stage', 'SEWING')->where('direction', 'OUT')->exists()) {
                        throw new RuntimeException("Bundle {$bundleNo}: belum selesai operasi sebelumnya (seq {$prevOp->seq}).");
                    }
                } else {
                    $completedSewingOps = ProductionScan::withoutGlobalScopes()->where('bundle_id', $bundle->id)
                        ->where('stage', 'SEWING')->where('direction', 'OUT')->pluck('operation_id')->unique();
                    if ($routingOps->pluck('operation_id')->diff($completedSewingOps)->isNotEmpty()) {
                        throw new RuntimeException("Bundle {$bundleNo}: seluruh operasi sewing wajib OUT sebelum finishing.");
                    }
                }
            }

            $scan = ProductionScan::create([
                'company_id' => $companyId, 'bundle_id' => $bundle->id, 'operation_id' => $operationId,
                'production_order_id' => $mo->id, 'line_id' => $lineId, 'employee_id' => $employeeId,
                'direction' => $direction, 'stage' => $stage, 'scanned_at' => now(),
            ]);
            $bundle->update(['current_stage' => $stage]);
            $this->advanceMoStatus($mo, $stage);
            return $scan;
        });
    }

    public function wipByStage(int $companyId, int $moId): array
    {
        if (! ProductionOrder::withoutGlobalScopes()->where('company_id', $companyId)->whereKey($moId)->exists()) {
            throw new RuntimeException('MO tidak ditemukan pada company ini.');
        }
        return Bundle::withoutGlobalScopes()->where('company_id', $companyId)->where('production_order_id', $moId)
            ->where('status', 'ACTIVE')->selectRaw('current_stage, COUNT(*) as bundle_count, SUM(qty) as pcs')
            ->groupBy('current_stage')->get()
            ->mapWithKeys(fn ($row) => [$row->current_stage => ['bundles' => (int) $row->bundle_count, 'pcs' => (float) $row->pcs]])->all();
    }

    public function dailyOutput(int $companyId, int $lineId, string $date): array
    {
        if (! DB::table('lines')->where('company_id', $companyId)->where('id', $lineId)->exists()) {
            throw new RuntimeException('Line tidak ditemukan pada company ini.');
        }
        return ProductionScan::withoutGlobalScopes()->where('production_scans.company_id', $companyId)
            ->where('production_scans.line_id', $lineId)->where('direction', 'OUT')->whereDate('scanned_at', $date)
            ->join('bundles', 'bundles.id', '=', 'production_scans.bundle_id')
            ->selectRaw('production_scans.stage, production_scans.operation_id, SUM(bundles.qty) as pcs, COUNT(DISTINCT production_scans.bundle_id) as bundles')
            ->groupBy('production_scans.stage', 'production_scans.operation_id')->get()->all();
    }

    private function advanceMoStatus(ProductionOrder $mo, string $stage): void
    {
        $target = $stage === 'SEWING' ? 'SEWING' : 'FINISHING';
        $order = ['PLANNED'=>0,'RELEASED'=>1,'CUTTING'=>2,'SEWING'=>3,'FINISHING'=>4,'QC'=>5,'PACKED'=>6,'CLOSED'=>7];
        if (($order[$target] ?? 0) > ($order[$mo->status] ?? 0)) $mo->update(['status' => $target]);
    }

    private function assertUserCompany(User $user, int $companyId): void
    {
        if ((int) $user->company_id !== $companyId && ! $user->companies()->whereKey($companyId)->exists()) {
            throw new RuntimeException('User tidak memiliki akses ke company scan.');
        }
    }
}
