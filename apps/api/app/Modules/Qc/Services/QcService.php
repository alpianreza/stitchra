<?php

namespace Modules\Qc\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Cutting\Models\Bundle;
use Modules\MasterData\Models\Customer;
use Modules\MasterData\Models\DefectLibrary;
use Modules\Production\Models\ProductionOrder;
use Modules\Qc\Models\QcInspection;
use RuntimeException;

class QcService
{
    public function __construct(
        private NumberingService $numbering,
        private AqlSamplingService $aql,
        private AuditService $audit,
        private NcrService $ncr,
    ) {}

    public function create(ProductionOrder $mo, string $stage, float $lotQty, User $user): QcInspection
    {
        $stage = strtoupper($stage);
        if (! in_array($stage, QcInspection::STAGES, true) || $lotQty <= 0) throw new RuntimeException('Stage atau lot quantity QC tidak valid.');

        return DB::transaction(function () use ($mo, $stage, $lotQty, $user): QcInspection {
            $lockedMo = ProductionOrder::withoutGlobalScopes()->whereKey($mo->id)->lockForUpdate()->firstOrFail();
            $this->assertAccess($user, (int) $lockedMo->company_id);
            $allowed = match ($stage) {
                'INLINE' => ['CUTTING','SEWING'], 'ENDLINE' => ['SEWING','FINISHING'], 'FINAL' => ['FINISHING','QC'],
            };
            if (! in_array($lockedMo->status, $allowed, true)) throw new RuntimeException("QC {$stage} tidak diizinkan saat MO berstatus {$lockedMo->status}.");
            if ($lotQty - (float) $lockedMo->qty_planned > 0.0001) throw new RuntimeException('Lot QC tidak boleh melebihi qty planned MO.');

            $previous = QcInspection::withoutGlobalScopes()->where('production_order_id', $lockedMo->id)
                ->where('stage', $stage)->orderByDesc('cycle')->lockForUpdate()->first();
            if ($previous !== null && $previous->verdict !== 'REWORK') {
                throw new RuntimeException('Cycle QC baru hanya dapat dibuat setelah verdict REWORK.');
            }
            $customer = Customer::withoutGlobalScopes()->where('company_id', $lockedMo->company_id)
                ->whereKey($lockedMo->salesOrder->customer_id)->firstOrFail();
            $config = $customer->aqlConfig;
            $payload = [
                'company_id'=>$lockedMo->company_id,'doc_no'=>$this->numbering->next($lockedMo->company_id,'QC'),
                'production_order_id'=>$lockedMo->id,'stage'=>$stage,'customer_id'=>$customer->id,
                'inspection_level'=>$config?->inspection_level ?? 'G2','aql_major'=>$config ? (float)$config->aql_major : 2.5,
                'aql_minor'=>$config ? (float)$config->aql_minor : 4.0,'aql_critical'=>$config ? (float)$config->aql_critical : 0,
                'lot_qty'=>$lotQty,'cycle'=>($previous?->cycle ?? 0)+1,'verdict'=>'PENDING','created_by'=>$user->id,
            ];
            if ($stage === 'FINAL') {
                $sample = $this->aql->sampleFor($lotQty);
                [$ac,$re] = $this->aql->acceptReject($sample['sample_size'], $payload['aql_major']);
                $payload += ['sample_size'=>$sample['sample_size'],'accept_major'=>$ac,'reject_major'=>$re];
            }
            $inspection = QcInspection::create($payload);
            if ($previous !== null) $this->ncr->linkReinspection($previous, $inspection, $user);
            $this->audit->record('create', $inspection, after: ['stage'=>$stage,'cycle'=>$inspection->cycle]);
            return $inspection;
        });
    }

    public function recordDefects(QcInspection $inspection, array $defects, User $user): QcInspection
    {
        if ($defects === []) throw new RuntimeException('Defect list tidak boleh kosong.');
        return DB::transaction(function () use ($inspection, $defects, $user): QcInspection {
            $locked = QcInspection::withoutGlobalScopes()->whereKey($inspection->id)->lockForUpdate()->firstOrFail();
            $this->assertAccess($user, (int) $locked->company_id);
            if ($locked->verdict !== 'PENDING') throw new RuntimeException('Inspeksi sudah ber-verdict — buat inspeksi baru setelah rework.');
            $mo = ProductionOrder::withoutGlobalScopes()->where('company_id',$locked->company_id)->whereKey($locked->production_order_id)->firstOrFail();

            foreach ($defects as $data) {
                $qty = (int) ($data['qty'] ?? 1);
                if ($qty <= 0 || $qty - (float) $locked->lot_qty > 0.0001) throw new RuntimeException('Qty defect harus > 0 dan tidak melebihi lot QC.');
                $defect = DefectLibrary::withoutGlobalScopes()->where('company_id',$locked->company_id)
                    ->where('is_active',true)->whereKey((int)($data['defect_id'] ?? 0))->first();
                if ($defect === null) throw new RuntimeException('Defect aktif tidak ditemukan pada company QC.');
                $bundleId = ! empty($data['bundle_id']) ? (int)$data['bundle_id'] : null;
                if ($bundleId !== null && ! Bundle::withoutGlobalScopes()->where('company_id',$locked->company_id)
                    ->where('production_order_id',$mo->id)->whereKey($bundleId)->exists()) {
                    throw new RuntimeException('Bundle defect bukan milik MO/company inspeksi.');
                }
                $operationId = ! empty($data['operation_id']) ? (int)$data['operation_id'] : null;
                if ($operationId !== null && ! $mo->routingVersion->operations()->where('operation_id',$operationId)->exists()) {
                    throw new RuntimeException('Operation defect tidak ada di routing snapshot MO.');
                }
                $locked->lines()->create([
                    'bundle_id'=>$bundleId,'operation_id'=>$operationId,'defect_id'=>$defect->id,
                    'severity'=>$defect->severity,'qty'=>$qty,'photo_path'=>$data['photo_path'] ?? null,'notes'=>$data['notes'] ?? null,
                ]);
            }
            $locked->update([
                'defects_critical'=>(int)$locked->lines()->where('severity','CRITICAL')->sum('qty'),
                'defects_major'=>(int)$locked->lines()->where('severity','MAJOR')->sum('qty'),
                'defects_minor'=>(int)$locked->lines()->where('severity','MINOR')->sum('qty'),'updated_by'=>$user->id,
            ]);
            return $locked->fresh('lines');
        });
    }

    public function finalize(QcInspection $inspection, User $user, ?string $manualVerdict = null): QcInspection
    {
        return DB::transaction(function () use ($inspection, $user, $manualVerdict): QcInspection {
            $locked = QcInspection::withoutGlobalScopes()->whereKey($inspection->id)->lockForUpdate()->firstOrFail();
            $this->assertAccess($user, (int)$locked->company_id);
            if ($locked->verdict !== 'PENDING') throw new RuntimeException('Inspeksi sudah ber-verdict.');
            $mo = ProductionOrder::withoutGlobalScopes()->where('company_id',$locked->company_id)->whereKey($locked->production_order_id)->lockForUpdate()->firstOrFail();
            if ($locked->stage === 'FINAL' && ! in_array($mo->status,['FINISHING','QC'],true)) {
                throw new RuntimeException('Final QC hanya dapat diselesaikan dari MO FINISHING/QC.');
            }
            if ($locked->stage === 'FINAL') {
                $result = $this->aql->verdict((float)$locked->lot_qty,(int)$locked->defects_major,(int)$locked->defects_minor,(int)$locked->defects_critical,(float)$locked->aql_major,(float)$locked->aql_minor);
                $verdict = $result['verdict'];
            } else {
                if (! in_array($manualVerdict,['PASS','FAIL'],true)) throw new RuntimeException('INLINE/ENDLINE memerlukan verdict manual PASS/FAIL.');
                $verdict = $manualVerdict;
            }
            $final = $verdict === 'FAIL' ? 'REWORK' : $verdict;
            $locked->update(['verdict'=>$final,'updated_by'=>$user->id]);
            if ($final === 'REWORK') $this->ncr->createFromInspection($locked->fresh(), $user);
            if ($final === 'PASS') $this->ncr->completeReinspection($locked->fresh(), $user);
            if ($final === 'PASS' && $locked->stage === 'FINAL' && $mo->status === 'FINISHING') $mo->update(['status'=>'QC','updated_by'=>$user->id]);
            $this->audit->record('update',$locked,after:['verdict'=>$final,'cycle'=>$locked->cycle]);
            return $locked->fresh(['ncr']);
        });
    }

    private function assertAccess(User $user, int $companyId): void
    {
        if ((int)$user->company_id !== $companyId && ! $user->companies()->whereKey($companyId)->exists()) throw new RuntimeException('User tidak memiliki akses ke company QC.');
    }
}
