<?php

namespace Modules\ProductDev\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Approval\ApprovalEngine;
use Modules\Core\Models\User;
use Modules\Core\Support\CurrentCompany;
use Modules\MasterData\Models\Operation;
use Modules\MasterData\Models\Style;
use Modules\ProductDev\Models\Routing;
use Modules\ProductDev\Models\RoutingVersion;
use RuntimeException;

class RoutingService
{
    public function __construct(private ApprovalEngine $approval) {}

    public function createVersion(int $styleId, array $operations, User $creator): RoutingVersion
    {
        $companyId = CurrentCompany::id() ?? (int) $creator->company_id;
        if (! Style::query()->where('company_id', $companyId)->whereKey($styleId)->exists()) {
            throw new RuntimeException('Style tidak ditemukan pada company aktif.');
        }
        if ($operations === []) {
            throw new RuntimeException('Routing wajib memiliki minimal satu operasi.');
        }

        foreach ($operations as $operation) {
            if (! Operation::query()->where('company_id', $companyId)->whereKey($operation['operation_id'] ?? null)->exists()) {
                throw new RuntimeException('Operation routing tidak ditemukan pada company aktif.');
            }
            if ((float) ($operation['smv'] ?? 0) <= 0) {
                throw new RuntimeException('SMV routing harus lebih besar dari nol.');
            }
        }

        return DB::transaction(function () use ($styleId, $operations, $creator): RoutingVersion {
            DB::table('routings')->insertOrIgnore([
                'style_id' => $styleId,
                'current_version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $routing = Routing::where('style_id', $styleId)->lockForUpdate()->firstOrFail();
            $nextVersion = (int) $routing->versions()->max('version_no') + 1;
            $totalSam = collect($operations)->sum(fn ($operation) => (float) $operation['smv']);

            $version = $routing->versions()->create([
                'version_no' => $nextVersion,
                'total_sam' => $totalSam,
                'status' => 'DRAFT',
                'created_by' => $creator->id,
            ]);

            foreach ($operations as $index => $operation) {
                $version->operations()->create([
                    'seq' => $operation['seq'] ?? ($index + 1),
                    'operation_id' => $operation['operation_id'],
                    'smv' => $operation['smv'],
                    'machine_type' => $operation['machine_type'] ?? null,
                ]);
            }

            return $version->load('operations');
        });
    }

    public function submit(RoutingVersion $version, User $submitter): void
    {
        DB::transaction(function () use ($version, $submitter): void {
            $locked = RoutingVersion::whereKey($version->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'DRAFT') {
                throw new RuntimeException('Hanya versi DRAFT yang bisa disubmit.');
            }
            if (! $locked->operations()->exists()) {
                throw new RuntimeException('Routing tanpa operasi tidak bisa disubmit.');
            }

            $locked->update(['status' => 'SUBMITTED']);
            $this->approval->submit($locked, 'ROUTING', $submitter);
        });
    }

    public function markApproved(RoutingVersion $version): void
    {
        DB::transaction(function () use ($version): void {
            $locked = RoutingVersion::whereKey($version->id)->lockForUpdate()->firstOrFail();
            $routing = Routing::whereKey($locked->routing_id)->lockForUpdate()->firstOrFail();

            $routing->versions()->where('id', '!=', $locked->id)->where('status', 'APPROVED')
                ->update(['status' => 'OBSOLETE']);
            $locked->update(['status' => 'APPROVED']);
            $routing->update(['current_version' => $locked->version_no]);
        });
    }

    public function activeVersion(int $styleId): ?RoutingVersion
    {
        return Routing::where('style_id', $styleId)->first()?->approvedVersion();
    }
}
