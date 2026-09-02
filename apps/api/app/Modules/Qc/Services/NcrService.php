<?php

namespace Modules\Qc\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Approval\ApprovalEngine;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Qc\Models\Disposition;
use Modules\Qc\Models\Ncr;
use Modules\Qc\Models\QcInspection;
use Modules\Qc\Models\ReworkOrder;
use RuntimeException;

class NcrService
{
    public function __construct(
        private NumberingService $numbering,
        private ApprovalEngine $approval,
        private AuditService $audit,
    ) {}

    public function createFromInspection(QcInspection $inspection, User $user): Ncr
    {
        return DB::transaction(function () use ($inspection, $user): Ncr {
            $locked = QcInspection::withoutGlobalScopes()->whereKey($inspection->id)->lockForUpdate()->firstOrFail();
            $this->assertAccess($user, (int) $locked->company_id);
            if ($locked->verdict !== 'REWORK') {
                throw new RuntimeException('NCR hanya dapat dibuat dari inspeksi ber-verdict REWORK.');
            }

            $existing = Ncr::withoutGlobalScopes()->where('qc_inspection_id', $locked->id)->first();
            if ($existing !== null) return $existing;

            $ncr = Ncr::create([
                'company_id' => $locked->company_id,
                'doc_no' => $this->numbering->next((int) $locked->company_id, 'NCR'),
                'qc_inspection_id' => $locked->id,
                'production_order_id' => $locked->production_order_id,
                'qty' => $locked->lot_qty,
                'status' => 'DRAFT',
                'created_by' => $user->id,
            ]);
            $this->audit->record('create', $ncr, after: ['qc_inspection_id' => $locked->id, 'qty' => $ncr->qty]);

            return $ncr;
        });
    }

    public function addDisposition(Ncr $ncr, array $data, User $user): Disposition
    {
        return DB::transaction(function () use ($ncr, $data, $user): Disposition {
            $locked = Ncr::withoutGlobalScopes()->whereKey($ncr->id)->lockForUpdate()->firstOrFail();
            $this->assertAccess($user, (int) $locked->company_id);
            if ($locked->status !== 'DRAFT') throw new RuntimeException('Disposition hanya dapat diubah saat NCR DRAFT.');

            $action = strtoupper((string) ($data['action'] ?? ''));
            $qty = (float) ($data['qty'] ?? 0);
            $targetStage = ! empty($data['target_stage']) ? strtoupper((string) $data['target_stage']) : null;
            if (! in_array($action, Disposition::ACTIONS, true) || $qty <= 0) {
                throw new RuntimeException('Action atau qty disposition tidak valid.');
            }
            if (in_array($action, Disposition::REWORK_ACTIONS, true) && ! in_array($targetStage, Disposition::TARGET_STAGES, true)) {
                throw new RuntimeException('REWORK/REPAIR memerlukan target stage CUTTING, SEWING, atau FINISHING.');
            }
            if (! in_array($action, Disposition::REWORK_ACTIONS, true)) $targetStage = null;

            $allocated = (float) Disposition::withoutGlobalScopes()->where('ncr_id', $locked->id)->sum('qty');
            if (($allocated + $qty) - (float) $locked->qty > 0.0001) {
                throw new RuntimeException('Total qty disposition tidak boleh melebihi qty NCR.');
            }

            $disposition = Disposition::create([
                'company_id' => $locked->company_id,
                'ncr_id' => $locked->id,
                'action' => $action,
                'qty' => $qty,
                'target_stage' => $targetStage,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);
            $this->audit->record('create', $disposition, after: ['ncr' => $locked->doc_no, 'action' => $action, 'qty' => $qty]);

            return $disposition;
        });
    }

    public function submit(Ncr $ncr, User $user): Ncr
    {
        return DB::transaction(function () use ($ncr, $user): Ncr {
            $locked = Ncr::withoutGlobalScopes()->whereKey($ncr->id)->lockForUpdate()->firstOrFail();
            $this->assertAccess($user, (int) $locked->company_id);
            if ($locked->status !== 'DRAFT') throw new RuntimeException('Hanya NCR DRAFT yang dapat disubmit.');
            $allocated = (float) Disposition::withoutGlobalScopes()->where('ncr_id', $locked->id)->sum('qty');
            if (abs($allocated - (float) $locked->qty) > 0.0001) {
                throw new RuntimeException('Seluruh qty NCR harus memiliki disposition sebelum submit.');
            }

            $this->approval->submit($locked, 'NCR', $user);
            $locked->update(['status' => 'SUBMITTED', 'updated_by' => $user->id]);
            $this->audit->record('submit', $locked, after: ['status' => 'SUBMITTED']);

            return $locked->fresh(['dispositions']);
        });
    }

    public function markApproved(int $ncrId, int $approverId): Ncr
    {
        return DB::transaction(function () use ($ncrId, $approverId): Ncr {
            $ncr = Ncr::withoutGlobalScopes()->whereKey($ncrId)->lockForUpdate()->firstOrFail();
            if ($ncr->status !== 'SUBMITTED') throw new RuntimeException('NCR tidak dalam status SUBMITTED.');

            $dispositions = Disposition::withoutGlobalScopes()->where('ncr_id', $ncr->id)->lockForUpdate()->get();
            foreach ($dispositions as $disposition) {
                $disposition->update(['approved_by' => $approverId, 'approved_at' => now(), 'updated_by' => $approverId]);
                if (in_array($disposition->action, Disposition::REWORK_ACTIONS, true)) {
                    $count = ReworkOrder::withoutGlobalScopes()->where('ncr_id', $ncr->id)
                        ->where('target_stage', $disposition->target_stage)->count() + 1;
                    ReworkOrder::withoutGlobalScopes()->firstOrCreate(
                        ['disposition_id' => $disposition->id],
                        [
                            'company_id' => $ncr->company_id,
                            'ncr_id' => $ncr->id,
                            'target_stage' => $disposition->target_stage,
                            'qty' => $disposition->qty,
                            'rework_count' => $count,
                            'status' => 'OPEN',
                            'created_by' => $approverId,
                        ],
                    );
                }
            }

            $ncr->update(['status' => 'APPROVED', 'updated_by' => $approverId]);
            $this->audit->record('approve', $ncr, after: ['status' => 'APPROVED']);

            return $ncr->fresh(['dispositions', 'reworkOrders']);
        });
    }

    public function markRejected(int $ncrId, int $approverId): Ncr
    {
        return DB::transaction(function () use ($ncrId, $approverId): Ncr {
            $ncr = Ncr::withoutGlobalScopes()->whereKey($ncrId)->lockForUpdate()->firstOrFail();
            if ($ncr->status !== 'SUBMITTED') return $ncr;
            $ncr->update(['status' => 'REJECTED', 'updated_by' => $approverId]);
            $this->audit->record('reject', $ncr, after: ['status' => 'REJECTED']);
            return $ncr->fresh();
        });
    }

    public function linkReinspection(QcInspection $source, QcInspection $reinspection, User $user): void
    {
        $ncr = Ncr::withoutGlobalScopes()->where('qc_inspection_id', $source->id)->first();
        if ($ncr === null) return;

        ReworkOrder::withoutGlobalScopes()->where('ncr_id', $ncr->id)->where('status', 'OPEN')->whereNull('reinspection_id')
            ->update(['reinspection_id' => $reinspection->id, 'updated_by' => $user->id, 'updated_at' => now()]);
    }

    public function completeReinspection(QcInspection $reinspection, User $user): void
    {
        $orders = ReworkOrder::withoutGlobalScopes()->where('reinspection_id', $reinspection->id)->where('status', 'OPEN')->lockForUpdate()->get();
        foreach ($orders as $order) {
            $order->update(['status' => 'CLOSED', 'updated_by' => $user->id]);
            $ncr = Ncr::withoutGlobalScopes()->whereKey($order->ncr_id)->lockForUpdate()->first();
            if ($ncr !== null && ! ReworkOrder::withoutGlobalScopes()->where('ncr_id', $ncr->id)->where('status', 'OPEN')->exists()) {
                $ncr->update(['status' => 'CLOSED', 'updated_by' => $user->id]);
                $this->audit->record('close', $ncr, after: ['reinspection_id' => $reinspection->id, 'verdict' => $reinspection->verdict]);
            }
        }
    }

    private function assertAccess(User $user, int $companyId): void
    {
        if ((int) $user->company_id !== $companyId && ! $user->companies()->whereKey($companyId)->exists()) {
            throw new RuntimeException('User tidak memiliki akses ke company NCR.');
        }
    }
}
