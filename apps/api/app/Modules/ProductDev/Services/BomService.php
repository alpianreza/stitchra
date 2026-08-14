<?php

namespace Modules\ProductDev\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Approval\ApprovalEngine;
use Modules\Core\Models\User;
use Modules\ProductDev\Models\Bom;
use Modules\ProductDev\Models\BomVersion;
use RuntimeException;

/**
 * BR-030: BOM versioned.
 * - Hanya versi APPROVED yang dipakai MRP/costing/produksi
 * - Perubahan pasca-approval = versi BARU (tidak edit in-place)
 * - Approve versi baru → versi lama otomatis OBSOLETE
 */
class BomService
{
    public function __construct(private ApprovalEngine $approval) {}

    /** Buat versi BOM baru (draft) beserta lines. */
    public function createVersion(int $styleId, array $lines, User $creator): BomVersion
    {
        return DB::transaction(function () use ($styleId, $lines, $creator): BomVersion {
            $bom = Bom::firstOrCreate(['style_id' => $styleId]);
            $nextVersion = (int) $bom->versions()->max('version_no') + 1;

            $version = $bom->versions()->create([
                'version_no' => $nextVersion,
                'status' => 'DRAFT',
                'created_by' => $creator->id,
            ]);

            foreach ($lines as $line) {
                $version->lines()->create($line);   // kolom sesuai BR-032
            }

            return $version->load('lines');
        });
    }

    /** Update lines — HANYA untuk versi DRAFT (BR-030). */
    public function updateDraftLines(BomVersion $version, array $lines): BomVersion
    {
        if ($version->status !== 'DRAFT') {
            throw new RuntimeException('BR-030: versi BOM yang sudah SUBMITTED/APPROVED tidak bisa diedit — buat versi baru.');
        }

        return DB::transaction(function () use ($version, $lines): BomVersion {
            $version->lines()->delete();
            foreach ($lines as $line) {
                $version->lines()->create($line);
            }

            return $version->load('lines');
        });
    }

    public function submit(BomVersion $version, User $submitter): void
    {
        if ($version->status !== 'DRAFT') {
            throw new RuntimeException('Hanya versi DRAFT yang bisa disubmit.');
        }
        if ($version->lines()->count() === 0) {
            throw new RuntimeException('BOM version tanpa lines tidak bisa disubmit.');
        }

        $version->update(['status' => 'SUBMITTED']);
        $this->approval->submit($version, 'BOM', $submitter);
    }

    /** Dipanggil setelah approval APPROVED (listener DocumentApproved). */
    public function markApproved(BomVersion $version): void
    {
        DB::transaction(function () use ($version): void {
            // Versi lama → OBSOLETE (hanya satu APPROVED aktif per style)
            $version->bom->versions()
                ->where('id', '!=', $version->id)
                ->where('status', 'APPROVED')
                ->update(['status' => 'OBSOLETE']);

            $version->update(['status' => 'APPROVED']);
            $version->bom->update(['current_version' => $version->version_no]);
        });
    }

    /** Versi APPROVED aktif untuk style — dipakai MRP/costing/SO gate (BR-023). */
    public function activeVersion(int $styleId): ?BomVersion
    {
        $bom = Bom::where('style_id', $styleId)->first();

        return $bom?->approvedVersion();
    }
}
