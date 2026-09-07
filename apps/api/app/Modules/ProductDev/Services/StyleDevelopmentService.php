<?php

namespace Modules\ProductDev\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Support\CurrentCompany;
use Modules\MasterData\Models\Style;
use Modules\ProductDev\Models\MeasurementChart;
use Modules\ProductDev\Models\StyleSpec;
use RuntimeException;

class StyleDevelopmentService
{
    public function __construct(private AuditService $audit) {}

    public function access(Style $style, User $user): void
    {
        $companyId = (int) $style->company_id;
        if ((CurrentCompany::id() !== null && CurrentCompany::id() !== $companyId)
            || ((int) $user->company_id !== $companyId && ! $user->companies()->whereKey($companyId)->exists())) {
            throw new RuntimeException('Style bukan milik company yang dapat diakses.');
        }
    }

    public function lockStyle(Style $style, User $user): Style
    {
        $this->access($style, $user);

        return Style::withoutGlobalScopes()->where('company_id', $style->company_id)
            ->whereNull('deleted_at')->whereKey($style->id)->lockForUpdate()->firstOrFail();
    }

    public function createSpec(Style $style, array $input, User $user): StyleSpec
    {
        $data = Validator::make($input, [
            'expected_version' => 'required|integer|min:0',
            'description' => 'nullable|string|max:20000',
            'construction_notes' => 'nullable|string|max:20000',
            'revision_notes' => 'nullable|string|max:5000',
        ])->validate();
        if (trim(($data['description'] ?? '').($data['construction_notes'] ?? '')) === '') {
            throw new RuntimeException('Isi deskripsi atau catatan konstruksi style.');
        }

        return DB::transaction(function () use ($style, $data, $user): StyleSpec {
            $style = $this->lockStyle($style, $user);
            $latest = (int) StyleSpec::where('style_id', $style->id)->max('version');
            $this->assertVersion($latest, (int) $data['expected_version']);
            $spec = StyleSpec::create([
                'style_id' => $style->id, 'version' => $latest + 1,
                'description' => $data['description'] ?? null,
                'construction_notes' => $data['construction_notes'] ?? null,
                'revision_notes' => $data['revision_notes'] ?? null, 'created_by' => $user->id,
            ]);
            $this->audit->record('create', $spec, after: $spec->toArray(), companyId: (int) $style->company_id);

            return $spec;
        }, 3);
    }

    public function createMeasurements(Style $style, array $input, User $user): MeasurementChart
    {
        $data = Validator::make($input, [
            'expected_version' => 'required|integer|min:0',
            'unit' => ['required', Rule::in(['CM', 'MM', 'IN'])],
            'revision_notes' => 'nullable|string|max:5000',
            'lines' => 'required|array|min:1|max:500',
            'lines.*.pom_code' => 'required|string|max:32',
            'lines.*.size_id' => 'required|integer|min:1',
            'lines.*.value' => 'required|numeric|min:0.001|max:9999999.999',
            'lines.*.tolerance' => 'nullable|numeric|min:0|max:9999999.999',
        ])->validate();

        return DB::transaction(function () use ($style, $data, $user): MeasurementChart {
            $style = $this->lockStyle($style, $user);
            $latest = (int) MeasurementChart::where('style_id', $style->id)->max('version');
            $this->assertVersion($latest, (int) $data['expected_version']);
            $chart = MeasurementChart::create([
                'style_id' => $style->id, 'version' => $latest + 1,
                'unit' => $data['unit'], 'revision_notes' => $data['revision_notes'] ?? null,
                'created_by' => $user->id,
            ]);
            $seen = [];
            foreach ($data['lines'] as $line) {
                $pom = mb_strtoupper(trim($line['pom_code']));
                $key = $pom.':'.$line['size_id'];
                if ($pom === '' || isset($seen[$key])) {
                    throw new RuntimeException('Point of measure × size kosong atau duplikat.');
                }
                $seen[$key] = true;
                if (! DB::table('sizes')->where('company_id', $style->company_id)->where('id', $line['size_id'])->exists()) {
                    throw new RuntimeException('Size berasal dari company lain.');
                }
                $chart->lines()->create([
                    'pom_code' => $pom, 'size_id' => $line['size_id'],
                    'value' => round((float) $line['value'], 3),
                    'tolerance' => isset($line['tolerance']) ? round((float) $line['tolerance'], 3) : null,
                ]);
            }
            $this->audit->record('create', $chart, after: $chart->load('lines')->toArray(), companyId: (int) $style->company_id);

            return $chart->load('lines.size');
        }, 3);
    }

    private function assertVersion(int $latest, int $expected): void
    {
        if ($latest !== $expected) {
            throw new RuntimeException('Versi sudah berubah. Muat ulang sebelum membuat revisi; versi lama tidak ditimpa.');
        }
    }
}