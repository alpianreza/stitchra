<?php

namespace Modules\Production\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Support\CurrentCompany;
use Modules\MasterData\Models\Style;
use Modules\ProductDev\Models\Sample;
use Modules\ProductDev\Models\SampleApproval;
use Modules\ProductDev\Services\StyleDevelopmentService;
use Modules\Production\Models\ProductionOrder;
use RuntimeException;

/** Explicit approved-sample selection. No implicit PP-only or automatic stage policy. */
class SampleGateService
{
    public function __construct(private StyleDevelopmentService $styles, private AuditService $audit) {}

    public function read(ProductionOrder $mo, User $user, ?string $search = null): array
    {
        $this->access($mo, $user);
        $sample = $this->selected($mo);
        $reason = $this->reason($sample);
        $candidates = Sample::withoutGlobalScopes()->where('company_id', $mo->company_id)->where('style_id', $mo->style_id)
            ->where('buyer_status', 'APPROVED')->whereHas('latestApproval', fn ($q) => $q->where('status', 'APPROVED'))
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('samples as newer')
                    ->whereColumn('newer.company_id', 'samples.company_id')->whereColumn('newer.style_id', 'samples.style_id')
                    ->whereColumn('newer.stage', 'samples.stage')->whereColumn('newer.version', '>=', 'samples.version')
                    ->whereColumn('newer.id', '!=', 'samples.id');
            });
        if ($search) {
            $candidates->where('doc_no', 'like', '%'.$search.'%');
        }

        return [
            'selected' => $sample?->only('id', 'doc_no', 'stage', 'version', 'buyer_status'),
            'ready' => $reason === null, 'reason' => $reason,
            'checked_at' => $mo->sample_gate_checked_at, 'release_snapshot' => $mo->sample_gate_snapshot,
            'can_select' => $mo->status === 'PLANNED' && $user->hasPermission('production.mo.update'),
            'stage_policy' => 'EXPLICIT_SELECTION_NO_IMPLICIT_STAGE',
            'candidates' => $candidates->select('id', 'doc_no', 'stage', 'version', 'buyer_status')->orderByDesc('id')->paginate(20),
        ];
    }

    public function select(ProductionOrder $mo, ?int $sampleId, User $user): ProductionOrder
    {
        $this->access($mo, $user);

        return DB::transaction(function () use ($mo, $sampleId, $user): ProductionOrder {
            $locked = ProductionOrder::withoutGlobalScopes()->where('company_id', $mo->company_id)->whereKey($mo->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'PLANNED') {
                throw new RuntimeException('Pilihan sample hanya dapat diubah saat MO PLANNED.');
            }
            $this->lockStyle($locked, $user);
            if ($sampleId !== null) {
                $sample = Sample::withoutGlobalScopes()->where('company_id', $locked->company_id)->where('style_id', $locked->style_id)
                    ->whereKey($sampleId)->lockForUpdate()->first();
                if (! $sample) {
                    throw new RuntimeException('Sample bukan milik style/company MO.');
                }
                $this->requireApproved($sample);
            }
            if (($locked->production_sample_id === null ? null : (int) $locked->production_sample_id) === $sampleId) {
                return $locked;
            }
            $before = $locked->only('production_sample_id', 'sample_gate_approval_id', 'sample_gate_snapshot', 'sample_gate_checked_at');
            $locked->update([
                'production_sample_id' => $sampleId, 'sample_gate_approval_id' => null,
                'sample_gate_snapshot' => null, 'sample_gate_checked_at' => null, 'updated_by' => $user->id,
            ]);
            $this->audit->record('select_sample', $locked, before: $before, after: ['production_sample_id' => $sampleId]);

            return $locked;
        }, 3);
    }

    /** Called inside the existing MO release transaction, before reservations. */
    public function verifyAndSnapshot(ProductionOrder $mo, User $user): ProductionOrder
    {
        $this->access($mo, $user);
        $this->lockStyle($mo, $user);
        $sample = $this->selected($mo, true);
        $approval = $this->requireApproved($sample);
        $mo->update([
            'sample_gate_approval_id' => $approval->id,
            'sample_gate_checked_at' => now(),
            'sample_gate_snapshot' => [
                'sample_id' => $sample->id, 'doc_no' => $sample->doc_no, 'stage' => $sample->stage,
                'version' => $sample->version, 'style_id' => $sample->style_id,
                'style_spec_id' => $sample->style_spec_id, 'measurement_chart_id' => $sample->measurement_chart_id,
                'tech_pack_id' => $sample->tech_pack_id, 'approval_id' => $approval->id,
                'buyer_status' => 'APPROVED', 'by_name' => $approval->by_name,
                'response_reference' => $approval->response_reference, 'recorded_by' => $approval->recorded_by,
                'response_recorded_at' => $approval->created_at?->toIso8601String(),
                'verified_by' => $user->id,
            ],
        ]);

        return $mo;
    }

    private function selected(ProductionOrder $mo, bool $lock = false): ?Sample
    {
        if (! $mo->production_sample_id) {
            return null;
        }
        $query = Sample::withoutGlobalScopes()->where('company_id', $mo->company_id)
            ->where('style_id', $mo->style_id)->whereKey($mo->production_sample_id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function reason(?Sample $sample): ?string
    {
        if (! $sample) {
            return 'Pilih sample APPROVED secara eksplisit sebelum release MO.';
        }
        if ($sample->isSuperseded()) {
            return 'Sample sudah memiliki revisi lebih baru atau versi legacy ambigu; pilih versi terbaru yang disetujui.';
        }
        if ($sample->buyer_status !== 'APPROVED' || $sample->latestApproval?->status !== 'APPROVED') {
            return 'Status dan respons buyer terbaru harus APPROVED. Label status saja tidak cukup.';
        }

        return null;
    }

    private function requireApproved(?Sample $sample): SampleApproval
    {
        if ($reason = $this->reason($sample)) {
            throw new RuntimeException($reason);
        }

        return $sample->latestApproval;
    }

    private function lockStyle(ProductionOrder $mo, User $user): void
    {
        $style = Style::withoutGlobalScopes()->where('company_id', $mo->company_id)->whereKey($mo->style_id)->firstOrFail();
        $this->styles->lockStyle($style, $user);
    }

    private function access(ProductionOrder $mo, User $user): void
    {
        $companyId = (int) $mo->company_id;
        if ((CurrentCompany::id() !== null && CurrentCompany::id() !== $companyId)
            || ((int) $user->company_id !== $companyId && ! $user->companies()->whereKey($companyId)->exists())) {
            throw new RuntimeException('MO bukan milik company yang dapat diakses.');
        }
    }
}
