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
use RuntimeException;

class BomService
{
    public function __construct(private ApprovalEngine $approval) {}

    public function createVersion(int $styleId, array $lines, User $creator): BomVersion
    {
        $companyId = CurrentCompany::id() ?? (int) $creator->company_id;
        $style = Style::query()->where('company_id', $companyId)->find($styleId);

        if ($style === null) {
            throw new RuntimeException('Style tidak ditemukan pada company aktif.');
        }
        if ($lines === []) {
            throw new RuntimeException('BOM wajib memiliki minimal satu line.');
        }

        $this->assertLinesBelongToCompany($lines, $styleId, $companyId);

        return DB::transaction(function () use ($styleId, $lines, $creator): BomVersion {
            DB::table('boms')->insertOrIgnore([
                'style_id' => $styleId,
                'current_version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $bom = Bom::where('style_id', $styleId)->lockForUpdate()->firstOrFail();
            $nextVersion = (int) $bom->versions()->max('version_no') + 1;

            $version = $bom->versions()->create([
                'version_no' => $nextVersion,
                'status' => 'DRAFT',
                'created_by' => $creator->id,
            ]);

            foreach ($lines as $line) {
                $version->lines()->create($line);
            }

            return $version->load('lines');
        });
    }

    public function updateDraftLines(BomVersion $version, array $lines): BomVersion
    {
        if ($lines === []) {
            throw new RuntimeException('BOM wajib memiliki minimal satu line.');
        }

        return DB::transaction(function () use ($version, $lines): BomVersion {
            $locked = BomVersion::whereKey($version->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'DRAFT') {
                throw new RuntimeException('BR-030: versi BOM yang sudah SUBMITTED/APPROVED tidak bisa diedit — buat versi baru.');
            }

            $companyId = (int) $locked->bom->style->company_id;
            $this->assertLinesBelongToCompany($lines, (int) $locked->bom->style_id, $companyId);

            $locked->lines()->delete();
            foreach ($lines as $line) {
                $locked->lines()->create($line);
            }

            return $locked->load('lines');
        });
    }

    public function submit(BomVersion $version, User $submitter): void
    {
        DB::transaction(function () use ($version, $submitter): void {
            $locked = BomVersion::whereKey($version->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'DRAFT') {
                throw new RuntimeException('Hanya versi DRAFT yang bisa disubmit.');
            }
            if (! $locked->lines()->exists()) {
                throw new RuntimeException('BOM version tanpa lines tidak bisa disubmit.');
            }

            $locked->update(['status' => 'SUBMITTED']);
            $this->approval->submit($locked, 'BOM', $submitter);
        });
    }

    public function markApproved(BomVersion $version): void
    {
        DB::transaction(function () use ($version): void {
            $locked = BomVersion::whereKey($version->id)->lockForUpdate()->firstOrFail();
            $bom = Bom::whereKey($locked->bom_id)->lockForUpdate()->firstOrFail();

            $bom->versions()->where('id', '!=', $locked->id)->where('status', 'APPROVED')
                ->update(['status' => 'OBSOLETE']);
            $locked->update(['status' => 'APPROVED']);
            $bom->update(['current_version' => $locked->version_no]);
        });
    }

    public function activeVersion(int $styleId): ?BomVersion
    {
        return Bom::where('style_id', $styleId)->first()?->approvedVersion();
    }

    private function assertLinesBelongToCompany(array $lines, int $styleId, int $companyId): void
    {
        foreach ($lines as $line) {
            $material = Material::query()->where('company_id', $companyId)->find($line['material_id'] ?? null);
            $uom = Uom::query()->where('company_id', $companyId)->find($line['uom_id'] ?? null);

            if ($material === null || $uom === null) {
                throw new RuntimeException('Material/UOM BOM tidak ditemukan pada company aktif.');
            }

            if (! empty($line['colorway_id'])) {
                $validColorway = Colorway::query()
                    ->where('company_id', $companyId)
                    ->where('style_id', $styleId)
                    ->whereKey($line['colorway_id'])
                    ->exists();

                if (! $validColorway) {
                    throw new RuntimeException('Colorway BOM harus berasal dari style dan company yang sama.');
                }
            }
        }
    }
}
