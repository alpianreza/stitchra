<?php

namespace Modules\ProductDev\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Approval\ApprovalEngine;
use Modules\Core\Models\User;
use Modules\ProductDev\Models\Routing;
use Modules\ProductDev\Models\RoutingVersion;
use RuntimeException;

/** BR-033: Routing versioned + SMV per operasi → total SAM style. */
class RoutingService
{
    public function __construct(private ApprovalEngine $approval) {}

    public function createVersion(int $styleId, array $operations, User $creator): RoutingVersion
    {
        return DB::transaction(function () use ($styleId, $operations, $creator): RoutingVersion {
            $routing = Routing::firstOrCreate(['style_id' => $styleId]);
            $nextVersion = (int) $routing->versions()->max('version_no') + 1;

            $totalSam = collect($operations)->sum(fn ($op) => (float) $op['smv']);

            $version = $routing->versions()->create([
                'version_no' => $nextVersion,
                'total_sam' => $totalSam,
                'status' => 'DRAFT',
                'created_by' => $creator->id,
            ]);

            foreach ($operations as $i => $op) {
                $version->operations()->create([
                    'seq' => $op['seq'] ?? ($i + 1),
                    'operation_id' => $op['operation_id'],
                    'smv' => $op['smv'],
                    'machine_type' => $op['machine_type'] ?? null,
                ]);
            }

            return $version->load('operations');
        });
    }

    public function submit(RoutingVersion $version, User $submitter): void
    {
        if ($version->status !== 'DRAFT') {
            throw new RuntimeException('Hanya versi DRAFT yang bisa disubmit.');
        }
        if ($version->operations()->count() === 0) {
            throw new RuntimeException('Routing tanpa operasi tidak bisa disubmit.');
        }

        $version->update(['status' => 'SUBMITTED']);
        $this->approval->submit($version, 'ROUTING', $submitter);
    }

    public function markApproved(RoutingVersion $version): void
    {
        DB::transaction(function () use ($version): void {
            $version->routing->versions()
                ->where('id', '!=', $version->id)
                ->where('status', 'APPROVED')
                ->update(['status' => 'OBSOLETE']);

            $version->update(['status' => 'APPROVED']);
            $version->routing->update(['current_version' => $version->version_no]);
        });
    }

    public function activeVersion(int $styleId): ?RoutingVersion
    {
        $routing = Routing::where('style_id', $styleId)->first();

        return $routing?->approvedVersion();
    }
}
