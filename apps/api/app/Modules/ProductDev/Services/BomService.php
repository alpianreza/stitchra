<?php

namespace Modules\ProductDev\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Approval\ApprovalEngine;
use Modules\Core\Models\User;
use Modules\Core\Support\CurrentCompany;
use Modules\MasterData\Models\Colorway;
use Modules\MasterData\Models\Material;
use Modules\MasterData\Models\Style;
use Modules\MasterData\Models\Uom;
use Modules\ProductDev\Models\Bom;
use Modules\ProductDev\Models\BomVersion;
use Modules\Production\Services\NamedProductionMeasureService;
use RuntimeException;

class BomService
{
    public function __construct(private ApprovalEngine $approval) {}

    public function createVersion(int $styleId, array $lines, User $creator): BomVersion
    {
        $companyId = CurrentCompany::id() ?? (int) $creator->company_id;
        if (! Style::query()->where('company_id', $companyId)->whereKey($styleId)->exists()) throw new RuntimeException('Style tidak ditemukan pada company aktif.');
        if ($lines === []) throw new RuntimeException('BOM wajib memiliki minimal satu line.');
        $this->assertLinesBelongToCompany($lines, $styleId, $companyId);
        return DB::transaction(function () use ($styleId, $lines, $creator): BomVersion {
            DB::table('boms')->insertOrIgnore(['style_id' => $styleId, 'current_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $bom = Bom::where('style_id', $styleId)->lockForUpdate()->firstOrFail();
            $version = $bom->versions()->create(['version_no' => (int) $bom->versions()->max('version_no') + 1, 'status' => 'DRAFT', 'created_by' => $creator->id]);
            foreach ($lines as $line) $version->lines()->create($this->normalizedLine($line));
            return $version->load('lines');
        });
    }

    public function updateDraftLines(BomVersion $version, array $lines): BomVersion
    {
        if ($lines === []) throw new RuntimeException('BOM wajib memiliki minimal satu line.');
        return DB::transaction(function () use ($version, $lines): BomVersion {
            $locked = BomVersion::whereKey($version->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'DRAFT') throw new RuntimeException('BR-030: versi BOM yang sudah SUBMITTED/APPROVED tidak bisa diedit — buat versi baru.');
            $companyId = (int) $locked->bom->style->company_id;
            $this->assertLinesBelongToCompany($lines, (int) $locked->bom->style_id, $companyId);
            $locked->lines()->delete(); foreach ($lines as $line) $locked->lines()->create($this->normalizedLine($line));
            return $locked->load('lines');
        });
    }

    public function submit(BomVersion $version, User $submitter): void
    {
        DB::transaction(function () use ($version, $submitter): void {
            $locked = BomVersion::with('lines')->whereKey($version->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'DRAFT') throw new RuntimeException('Hanya versi DRAFT yang bisa disubmit.');
            if ($locked->lines->isEmpty()) throw new RuntimeException('BOM version tanpa lines tidak bisa disubmit.');
            $this->assertLinesBelongToCompany($locked->lines->map->toArray()->all(), (int) $locked->bom->style_id, (int) $locked->bom->style->company_id);
            $locked->update(['status' => 'SUBMITTED']); $this->approval->submit($locked, 'BOM', $submitter);
        });
    }

    public function markApproved(BomVersion $version): void
    {
        DB::transaction(function () use ($version): void {
            $locked = BomVersion::whereKey($version->id)->lockForUpdate()->firstOrFail();
            $bom = Bom::whereKey($locked->bom_id)->lockForUpdate()->firstOrFail();
            $bom->versions()->where('id', '!=', $locked->id)->where('status', 'APPROVED')->update(['status' => 'OBSOLETE']);
            $locked->update(['status' => 'APPROVED']); $bom->update(['current_version' => $locked->version_no]);
        });
    }

    public function activeVersion(int $styleId): ?BomVersion { return Bom::where('style_id', $styleId)->first()?->approvedVersion(); }

    private function normalizedLine(array $line): array
    {
        $line['is_backflush'] = (bool) ($line['is_backflush'] ?? false);
        $line['backflush_stage'] = $line['is_backflush'] ? strtoupper((string) $line['backflush_stage']) : null;
        return $line;
    }

    private function assertLinesBelongToCompany(array $lines, int $styleId, int $companyId): void
    {
        $perMaterial = [];
        foreach ($lines as $line) {
            $material = Material::query()->where('company_id', $companyId)->find($line['material_id'] ?? null);
            $uom = Uom::query()->where('company_id', $companyId)->find($line['uom_id'] ?? null);
            if ($material === null || $uom === null) throw new RuntimeException('Material/UOM BOM tidak ditemukan pada company aktif.');
            if (! empty($line['colorway_id']) && ! Colorway::query()->where('company_id', $companyId)->where('style_id', $styleId)->whereKey($line['colorway_id'])->exists()) {
                throw new RuntimeException('Colorway BOM harus berasal dari style dan company yang sama.');
            }
            $backflush = (bool) ($line['is_backflush'] ?? false); $stage = $line['backflush_stage'] ?? null;
            $stage = $stage === null || $stage === '' ? null : strtoupper((string) $stage);
            if ($backflush && $material->isFabric()) throw new RuntimeException('BR-066: fabric wajib ACTUAL melalui Lay Roll dan tidak boleh BACKFLUSH.');
            if ($backflush && ($stage === null || ! in_array($stage, NamedProductionMeasureService::BACKFLUSH_STAGES, true))) throw new RuntimeException('BR-066: material BACKFLUSH wajib memiliki satu Named Stage.');
            if (! $backflush && $stage !== null) throw new RuntimeException('BR-066: backflush_stage hanya boleh diisi untuk material BACKFLUSH.');
            if ($backflush && ((int) $material->use_uom_id === 0 || (int) $material->use_uom_id !== (int) $uom->id)) throw new RuntimeException('BR-066: UOM source BACKFLUSH wajib sama dengan material use-UOM.');
            $signature = [$backflush, $stage, (int) $uom->id];
            if (isset($perMaterial[$material->id]) && $perMaterial[$material->id] !== $signature) throw new RuntimeException('BR-066: satu material dalam BOM wajib memakai satu method, Named Stage, dan UOM yang sama.');
            $perMaterial[$material->id] = $signature;
        }
    }
}
