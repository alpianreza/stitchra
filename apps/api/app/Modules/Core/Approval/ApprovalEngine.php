<?php

namespace Modules\Core\Approval;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\ApprovalFlow;
use Modules\Core\Models\ApprovalRequest;
use Modules\Core\Models\ApprovalStepInstance;
use Modules\Core\Models\User;
use RuntimeException;

/**
 * BR-015: Approval engine terpusat.
 * - Sequential & parallel flow, rejection, revision, delegation, history lengkap
 * - Threshold nilai dari approval matrix (flow steps), bukan hard-code
 * - Semua keputusan tercatat di approval_step_instances
 */
class ApprovalEngine
{
    /** Daftarkan dokumen ke flow aktif untuk doc_type-nya. */
    public function submit(Model $document, string $docType, User $submitter): ApprovalRequest
    {
        $flow = ApprovalFlow::withoutGlobalScopes()
            ->where('company_id', $document->company_id)
            ->where('doc_type', $docType)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();

        if ($flow === null) {
            throw new RuntimeException("Approval flow aktif belum ada untuk doc_type [{$docType}].");
        }

        return DB::transaction(function () use ($document, $docType, $flow, $submitter): ApprovalRequest {
            // Serialize submissions for the same document. This closes the race where
            // two callers both observe that no active request exists.
            $lockedDocument = $document->newQueryWithoutScopes()
                ->whereKey($document->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedDocument === null) {
                throw new RuntimeException('Dokumen approval tidak ditemukan.');
            }

            $existing = ApprovalRequest::withoutGlobalScopes()
                ->where('company_id', $lockedDocument->company_id)
                ->where('doc_type', $docType)
                ->where('doc_id', $lockedDocument->getKey())
                ->where('is_active', true)
                ->where('status', 'PENDING')
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            return ApprovalRequest::create([
                'company_id' => $lockedDocument->company_id,
                'flow_id' => $flow->id,
                'doc_type' => $docType,
                'doc_id' => $lockedDocument->getKey(),
                'status' => 'PENDING',
                'current_step' => 1,
                'is_active' => true,
                'submitted_by' => $submitter->id,
                'submitted_at' => now(),
            ]);
        });
    }

    /** Approve satu step; lanjut ke step berikutnya atau selesaikan request. */
    public function approve(ApprovalRequest $request, User $approver, ?string $note = null, ?User $delegatedFrom = null): ApprovalRequest
    {
        $expectedStep = (int) $request->current_step;

        return DB::transaction(function () use ($request, $approver, $note, $delegatedFrom, $expectedStep): ApprovalRequest {
            $locked = $this->lockCurrentRequest($request, $expectedStep);
            $this->assertCanAct($locked, $approver);
            $this->recordStep($locked, $approver, 'APPROVED', $note, $delegatedFrom);

            $flow = $locked->flow()->with('steps')->first();
            $maxStep = (int) $flow->steps->max('step_no');

            if ($locked->current_step >= $maxStep) {
                $locked->fill([
                    'status' => 'APPROVED',
                    'is_active' => false,
                    'completed_at' => now(),
                ])->save();

                DB::afterCommit(static fn () => event(new Events\DocumentApproved($locked)));
            } else {
                $locked->increment('current_step');
            }

            return $locked->refresh();
        });
    }

    public function reject(ApprovalRequest $request, User $approver, ?string $note = null): ApprovalRequest
    {
        $expectedStep = (int) $request->current_step;

        return DB::transaction(function () use ($request, $approver, $note, $expectedStep): ApprovalRequest {
            $locked = $this->lockCurrentRequest($request, $expectedStep);
            $this->assertCanAct($locked, $approver);
            $this->recordStep($locked, $approver, 'REJECTED', $note);

            $locked->fill([
                'status' => 'REJECTED',
                'is_active' => false,
                'completed_at' => now(),
            ])->save();

            DB::afterCommit(static fn () => event(new Events\DocumentRejected($locked)));

            return $locked->refresh();
        });
    }

    /** Minta revisi — dokumen kembali ke submitter, request ditutup. */
    public function requestRevision(ApprovalRequest $request, User $approver, string $note): ApprovalRequest
    {
        $expectedStep = (int) $request->current_step;

        return DB::transaction(function () use ($request, $approver, $note, $expectedStep): ApprovalRequest {
            $locked = $this->lockCurrentRequest($request, $expectedStep);
            $this->assertCanAct($locked, $approver);
            $this->recordStep($locked, $approver, 'REVISION', $note);

            $locked->fill([
                'status' => 'REVISION',
                'is_active' => false,
                'completed_at' => now(),
            ])->save();

            return $locked->refresh();
        });
    }

    private function lockCurrentRequest(ApprovalRequest $request, int $expectedStep): ApprovalRequest
    {
        $locked = ApprovalRequest::withoutGlobalScopes()
            ->whereKey($request->getKey())
            ->lockForUpdate()
            ->first();

        if ($locked === null) {
            throw new RuntimeException('Approval request tidak ditemukan.');
        }

        $this->assertPending($locked);

        // A request made from a stale screen must not accidentally approve the
        // next step after another approver has already advanced the workflow.
        if ((int) $locked->current_step !== $expectedStep) {
            throw new RuntimeException('Step approval sudah berubah. Muat ulang data sebelum melanjutkan.');
        }

        return $locked;
    }

    private function recordStep(ApprovalRequest $request, User $approver, string $decision, ?string $note, ?User $delegatedFrom = null): void
    {
        ApprovalStepInstance::create([
            'request_id' => $request->id,
            'step_no' => $request->current_step,
            'approver_id' => $approver->id,
            'delegated_from' => $delegatedFrom?->id,
            'decision' => $decision,
            'note' => $note,
            'decided_at' => now(),
        ]);
    }

    private function assertPending(ApprovalRequest $request): void
    {
        if ($request->status !== 'PENDING' || ! $request->is_active) {
            throw new RuntimeException('Approval request tidak dalam status PENDING.');
        }
    }

    /** Approver harus memiliki role yang dipersyaratkan step aktif. */
    private function assertCanAct(ApprovalRequest $request, User $approver): void
    {
        $step = $request->flow->steps->firstWhere('step_no', $request->current_step);

        if ($step === null) {
            throw new RuntimeException('Step approval tidak ditemukan.');
        }

        $hasRole = $approver->roles()->where('roles.id', $step->role_id)->exists();

        if (! $hasRole && ! $approver->hasPermission('core.approval.manage')) {
            throw new RuntimeException('User tidak berhak approve step ini.');
        }
    }
}
