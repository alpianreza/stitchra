<?php

namespace Modules\ProductDev\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\MasterData\Models\Style;
use Modules\ProductDev\Models\Sample;
use Modules\ProductDev\Models\SampleApproval;
use RuntimeException;

class SampleService
{
    public function __construct(
        private StyleDevelopmentService $styles,
        private NumberingService $numbering,
        private AuditService $audit,
    ) {}

    public function create(Style $style, array $input, User $user): Sample
    {
        $data = Validator::make($input, [
            'stage' => ['required', Rule::in(Sample::STAGES)],
            'style_spec_id' => 'nullable|integer|min:1',
            'measurement_chart_id' => 'nullable|integer|min:1',
            'tech_pack_id' => 'nullable|integer|min:1',
            'revision_of_id' => 'nullable|integer|min:1',
            'notes' => 'nullable|string|max:5000', 'request_key' => 'required|uuid',
        ])->validate();

        return DB::transaction(function () use ($style, $data, $user): Sample {
            $style = $this->styles->lockStyle($style, $user);
            $existing = Sample::withoutGlobalScopes()->where('company_id', $style->company_id)
                ->where('request_key', $data['request_key'])->first();
            if ($existing) {
                foreach (['stage', 'style_spec_id', 'measurement_chart_id', 'tech_pack_id', 'notes'] as $key) {
                    if ((string) $existing->getAttribute($key) !== (string) ($data[$key] ?? null)) {
                        throw new RuntimeException('Sample request key sudah dipakai untuk payload berbeda.');
                    }
                }
                if (isset($data['revision_of_id']) && (int) $existing->revision_of_id !== (int) $data['revision_of_id']) {
                    throw new RuntimeException('Sample request key sudah dipakai untuk sumber revisi berbeda.');
                }
                if ((int) $existing->style_id !== (int) $style->id) {
                    throw new RuntimeException('Sample request key sudah dipakai untuk style lain.');
                }

                return $existing->load('style', 'approvals');
            }
            foreach (['style_spec_id' => 'style_specs', 'measurement_chart_id' => 'measurement_charts', 'tech_pack_id' => 'tech_packs'] as $key => $table) {
                if (! empty($data[$key])) {
                    $reference = DB::table($table)->where('style_id', $style->id)->where('id', $data[$key]);
                    if ($table === 'tech_packs') {
                        $reference->where('company_id', $style->company_id);
                    }
                    if (! $reference->exists()) {
                        throw new RuntimeException("Referensi {$key} bukan versi milik style ini.");
                    }
                }
            }
            $latest = Sample::withoutGlobalScopes()->where('company_id', $style->company_id)
                ->where('style_id', $style->id)->where('stage', $data['stage'])->orderByDesc('version')->orderByDesc('id')->first();
            if (! empty($data['revision_of_id']) && (! $latest || (int) $latest->id !== (int) $data['revision_of_id'])) {
                throw new RuntimeException('Revisi harus menunjuk sample terbaru pada style dan stage yang sama.');
            }
            $sample = Sample::create([
                'company_id' => $style->company_id, 'style_id' => $style->id,
                'doc_no' => $this->numbering->next((int) $style->company_id, 'SMPL'),
                'stage' => $data['stage'], 'version' => (int) ($latest?->version ?? 0) + 1,
                'buyer_status' => 'PENDING',
                'style_spec_id' => $data['style_spec_id'] ?? null,
                'measurement_chart_id' => $data['measurement_chart_id'] ?? null,
                'tech_pack_id' => $data['tech_pack_id'] ?? null,
                'revision_of_id' => $latest?->id,
                'notes' => $data['notes'] ?? null, 'request_key' => $data['request_key'], 'created_by' => $user->id,
            ]);
            $this->audit->record('create', $sample, after: $sample->toArray());

            return $sample->load('style', 'approvals');
        }, 3);
    }

    /** Record evidence supplied by an internal user; this is not a buyer sign-in. */
    public function recordResponse(Sample $sample, array $input, User $user): SampleApproval
    {
        $data = Validator::make($input, [
            'status' => ['required', Rule::in(['APPROVED', 'REJECTED', 'COMMENTED'])],
            'by_name' => 'required|string|max:255',
            'comment' => 'nullable|string|max:5000',
            'response_reference' => 'required|string|max:255',
            'request_key' => 'required|uuid',
        ])->validate();
        $data['by_name'] = trim($data['by_name']);
        $data['response_reference'] = trim($data['response_reference']);
        if ($data['by_name'] === '' || $data['response_reference'] === '') {
            throw new RuntimeException('Nama buyer dan referensi respons wajib diisi.');
        }
        if ($data['status'] !== 'APPROVED' && trim($data['comment'] ?? '') === '') {
            throw new RuntimeException('Respons REJECTED/COMMENTED memerlukan komentar.');
        }

        return DB::transaction(function () use ($sample, $data, $user): SampleApproval {
            $style = Style::withoutGlobalScopes()->where('company_id', $sample->company_id)->whereKey($sample->style_id)->firstOrFail();
            $this->styles->lockStyle($style, $user);
            $locked = Sample::withoutGlobalScopes()->where('company_id', $sample->company_id)->whereKey($sample->id)->lockForUpdate()->firstOrFail();
            $existing = $locked->approvals()->where('request_key', $data['request_key'])->first();
            if ($existing) {
                foreach (['status', 'by_name', 'comment', 'response_reference'] as $key) {
                    if ((string) $existing->getAttribute($key) !== (string) ($data[$key] ?? null)) {
                        throw new RuntimeException('Response key sudah dipakai untuk respons berbeda.');
                    }
                }

                return $existing;
            }
            if ($locked->isSuperseded()) {
                throw new RuntimeException('Sample sudah direvisi. Catat keputusan pada versi terbaru.');
            }
            $approval = $locked->approvals()->create([
                ...$data, 'recorded_by' => $user->id,
            ]);
            $before = ['buyer_status' => $locked->buyer_status];
            $locked->update(['buyer_status' => $data['status'], 'updated_by' => $user->id]);
            $this->audit->record('buyer_response', $locked, before: $before, after: [
                'buyer_status' => $locked->buyer_status, 'response' => $approval->toArray(),
            ]);

            return $approval;
        }, 3);
    }
}