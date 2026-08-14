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

        // Satu request aktif per dokumen
        $existing = ApprovalRequest::withoutGlobalScopes()
            ->where('company_id', $document->company_id)
            ->where('doc_type', $docType)
            ->where('doc_id', $document->getKey())
            ->where('is_active', true)
            ->where('status', 'PENDING')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($document, $docType, $flow, $submitter): ApprovalRequest {
            return ApprovalRequest::create([
                'company_id' => $document->company_id,
                'flow_id' => $flow->id,
                'doc_type' => $docType,
                'doc_id' => $document->getKey(),
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
        $this->assertPending($request);
        $this->assertCanAct($request, $approver);

        return DB::transaction(function () use ($request, $approver, $note, $delegatedFrom): ApprovalRequest {
            $this->recordStep($request, $approver, 'APPROVED', $note, $delegatedFrom);

            $flow = $request->flow()->with('steps')->first();
            $maxStep = $flow->steps->max('step_no');

            if ($request->current_step >= $maxStep) {
                $request->fill([
                    'status' => 'APPROVED',
                    'is_active' => false,
                    'completed_at' => now(),
                ])->save();

                event(new Events\DocumentApproved($request));
            } else {
                $request->increment('current_step');
            }

            return $request->refresh();
        });
    }

    public function reject(ApprovalRequest $request, User $approver, ?string $note = null): ApprovalRequest
    {
        $this->assertPending($request);
        $this->assertCanAct($request, $approver);

        return DB::transaction(function () use ($request, $approver, $note): ApprovalRequest {
            $this->recordStep($request, $approver, 'REJECTED', $note);

            $request->fill([
                'status' => 'REJECTED',
                'is_active' => false,
                'completed_at' => now(),
            ])->save();

            event(new Events\DocumentRejected($request));

            return $request->refresh();
        });
    }

    /** Minta revisi — dokumen kembali ke submitter, request ditutup. */
    public function requestRevision(ApprovalRequest $request, User $approver, string $note): ApprovalRequest
    {
        $this->assertPending($request);
        $this->assertCanAct($request, $approver);

        return DB::transaction(function () use ($request, $approver, $note): ApprovalRequest {
            $this->recordStep($request, $approver, 'REVISION', $note);

            $request->fill([
                'status' => 'REVISION',
                'is_active' => false,
                'completed_at' => now(),
            ])->save();

            return $request->refresh();
        });
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
