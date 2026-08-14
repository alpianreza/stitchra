<?php

namespace Modules\Qc\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\MasterData\Models\Customer;
use Modules\Qc\Models\QcInspection;
use Modules\Production\Models\ProductionOrder;
use RuntimeException;

/**
 * BR-070/071: QC inline/endline/final; final = sampling AQL per buyer (BR-008, snapshot).
 * BR-073: FAIL → REWORK → inspeksi ulang (cycle naik).
 */
class QcService
{
    public function __construct(
        private NumberingService $numbering,
        private AqlSamplingService $aql,
        private AuditService $audit,
    ) {}

    /** Buat inspeksi; FINAL menghitung sample size + Ac/Re otomatis dari config buyer. */
    public function create(ProductionOrder $mo, string $stage, float $lotQty, User $user): QcInspection
    {
        if (in_array($mo->status, ['CLOSED', 'CANCELLED'], true)) {
            throw new RuntimeException("MO {$mo->doc_no} sudah {$mo->status}.");
        }

        return DB::transaction(function () use ($mo, $stage, $lotQty, $user): QcInspection {
            // Snapshot AQL dari customer (BR-008) — default G-II 2.5/4.0/0
            $customer = Customer::withoutGlobalScopes()->find($mo->salesOrder->customer_id);
            $aqlConfig = $customer?->aqlConfig;

            $payload = [
                'company_id' => $mo->company_id,
                'doc_no' => $this->numbering->next($mo->company_id, 'QC'),
                'production_order_id' => $mo->id,
                'stage' => $stage,
                'customer_id' => $customer?->id,
                'inspection_level' => $aqlConfig?->inspection_level ?? 'G2',
                'aql_major' => $aqlConfig ? (float) $aqlConfig->aql_major : 2.5,
                'aql_minor' => $aqlConfig ? (float) $aqlConfig->aql_minor : 4.0,
                'aql_critical' => $aqlConfig ? (float) $aqlConfig->aql_critical : 0,
                'lot_qty' => $lotQty,
                'cycle' => (int) QcInspection::where('production_order_id', $mo->id)->where('stage', $stage)->max('cycle') + 1,
                'verdict' => 'PENDING',
                'created_by' => $user->id,
            ];

            if ($stage === 'FINAL') {
                $sample = $this->aql->sampleFor($lotQty);
                [$ac, $re] = $this->aql->acceptReject($sample['sample_size'], $payload['aql_major']);
                $payload['sample_size'] = $sample['sample_size'];
                $payload['accept_major'] = $ac;
                $payload['reject_major'] = $re;
            }

            return QcInspection::create($payload);
        });
    }

    /** Catat defect dari library (BR-072); severity di-snapshot. */
    public function recordDefects(QcInspection $inspection, array $defects, User $user): QcInspection
    {
        if ($inspection->verdict !== 'PENDING') {
            throw new RuntimeException('Inspeksi sudah ber-verdict — buat inspeksi baru (rework cycle).');
        }

        return DB::transaction(function () use ($inspection, $defects, $user): QcInspection {
            foreach ($defects as $d) {
                $defect = \Modules\MasterData\Models\DefectLibrary::findOrFail($d['defect_id']);
                $inspection->lines()->create([
                    'bundle_id' => $d['bundle_id'] ?? null,
                    'operation_id' => $d['operation_id'] ?? null,
                    'defect_id' => $defect->id,
                    'severity' => $defect->severity,
                    'qty' => $d['qty'] ?? 1,
                    'photo_path' => $d['photo_path'] ?? null,
                    'notes' => $d['notes'] ?? null,
                ]);
            }

            // Agregasi per severity
            $inspection->update([
                'defects_critical' => (int) $inspection->lines()->where('severity', 'CRITICAL')->sum('qty'),
                'defects_major' => (int) $inspection->lines()->where('severity', 'MAJOR')->sum('qty'),
                'defects_minor' => (int) $inspection->lines()->where('severity', 'MINOR')->sum('qty'),
            ]);

            return $inspection->fresh('lines');
        });
    }

    /**
     * Finalisasi verdict.
     * FINAL: otomatis dari AQL (BR-071). INLINE/ENDLINE: manual PASS/FAIL dari inspector.
     * FAIL → verdict REWORK + MO tetap QC (BR-073 loop; cycle naik di inspeksi berikutnya).
     */
    public function finalize(QcInspection $inspection, User $user, ?string $manualVerdict = null): QcInspection
    {
        if ($inspection->verdict !== 'PENDING') {
            throw new RuntimeException('Inspeksi sudah ber-verdict.');
        }

        return DB::transaction(function () use ($inspection, $user, $manualVerdict): QcInspection {
            if ($inspection->stage === 'FINAL') {
                $result = $this->aql->verdict(
                    (float) $inspection->lot_qty,
                    $inspection->defects_major,
                    $inspection->defects_minor,
                    $inspection->defects_critical,
                    (float) $inspection->aql_major,
                    (float) $inspection->aql_minor,
                );
                $verdict = $result['verdict'];
            } else {
                if (! in_array($manualVerdict, ['PASS', 'FAIL'], true)) {
                    throw new RuntimeException('INLINE/ENDLINE memerlukan verdict manual PASS/FAIL.');
                }
                $verdict = $manualVerdict;
            }

            $final = $verdict === 'FAIL' ? 'REWORK' : $verdict;   // BR-073: FAIL masuk loop rework
            $inspection->update(['verdict' => $final, 'updated_by' => $user->id]);

            $mo = $inspection->productionOrder;
            if ($final === 'PASS' && $inspection->stage === 'FINAL' && $mo->status !== 'PACKED') {
                // QC final PASS → MO siap packing (BR-082); status QC ditandai
                if ($mo->status === 'FINISHING') {
                    $mo->update(['status' => 'QC']);
                }
            }

            $this->audit->record('update', $inspection, after: ['verdict' => $final, 'cycle' => $inspection->cycle]);

            return $inspection->fresh();
        });
    }
}
