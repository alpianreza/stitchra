<?php

namespace Modules\ShopFloor\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Cutting\Models\Bundle;
use Modules\MasterData\Models\DefectLibrary;
use Modules\Production\Models\ProductionOrder;
use Modules\Qc\Models\ReworkOrder;
use Modules\ShopFloor\Models\ReworkRecord;
use RuntimeException;

class ReworkService
{
    public function __construct(private AuditService $audit) {}

    public function record(int $companyId, string $bundleNo, array $data, User $user): ReworkRecord
    {
        return DB::transaction(function () use ($companyId, $bundleNo, $data, $user): ReworkRecord {
            $this->assertAccess($user, $companyId);
            $bundle = Bundle::withoutGlobalScopes()->where('company_id', $companyId)
                ->where('bundle_no', $bundleNo)->whereIn('status', ['ACTIVE','REWORK'])->lockForUpdate()->first();
            if ($bundle === null) throw new RuntimeException('Bundle tidak ditemukan atau tidak dapat dirework.');
            $qty = (float) ($data['qty'] ?? 0);
            if ($qty <= 0 || $qty - (float) $bundle->qty > 0.0001) throw new RuntimeException('Qty rework harus > 0 dan tidak melebihi qty bundle.');

            $order = null;
            if (! empty($data['rework_order_id'])) {
                $order = ReworkOrder::withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'OPEN')
                    ->whereKey((int) $data['rework_order_id'])->lockForUpdate()->first();
                if ($order === null) throw new RuntimeException('Rework order NCR tidak ditemukan atau tidak OPEN.');
                if ((int) $order->ncr->production_order_id !== (int) $bundle->production_order_id) {
                    throw new RuntimeException('Bundle bukan milik MO pada NCR rework order.');
                }
                if ($order->bundle_id !== null && (int) $order->bundle_id !== (int) $bundle->id) {
                    throw new RuntimeException('Rework order sudah ditautkan ke bundle lain.');
                }
                $recorded = (float) ReworkRecord::withoutGlobalScopes()->where('rework_order_id', $order->id)->sum('qty');
                if (($recorded + $qty) - (float) $order->qty > 0.0001) {
                    throw new RuntimeException('Total rework record melebihi qty rework order.');
                }
                if ($order->bundle_id === null) $order->update(['bundle_id' => $bundle->id, 'updated_by' => $user->id]);
            }

            $defect = DefectLibrary::withoutGlobalScopes()->where('company_id', $companyId)
                ->where('is_active', true)->whereKey((int) ($data['defect_id'] ?? 0))->first();
            if ($defect === null) throw new RuntimeException('Defect aktif tidak ditemukan pada company ini.');
            $operationId = ! empty($data['operation_id']) ? (int) $data['operation_id'] : null;
            if ($operationId !== null) {
                $mo = ProductionOrder::withoutGlobalScopes()->where('company_id', $companyId)->whereKey($bundle->production_order_id)->firstOrFail();
                if (! $mo->routingVersion->operations()->where('operation_id', $operationId)->exists()) {
                    throw new RuntimeException('Operasi rework tidak ada di routing snapshot MO.');
                }
            }

            $record = ReworkRecord::create([
                'company_id'=>$companyId,'rework_order_id'=>$order?->id,'bundle_id'=>$bundle->id,'operation_id'=>$operationId,
                'defect_id'=>$defect->id,'qty'=>$qty,'notes'=>$data['notes'] ?? null,'created_by'=>$user->id,
            ]);
            $bundle->update(['status'=>'REWORK']);
            $this->audit->record('create', $record, after: ['bundle'=>$bundleNo,'rework_order_id'=>$order?->id,'defect'=>$defect->code,'qty'=>$qty]);
            return $record->load('reworkOrder','defect','operation');
        });
    }

    public function resolve(ReworkRecord $rework, User $user): ReworkRecord
    {
        return DB::transaction(function () use ($rework, $user): ReworkRecord {
            $locked = ReworkRecord::withoutGlobalScopes()->whereKey($rework->id)->lockForUpdate()->firstOrFail();
            $this->assertAccess($user, (int) $locked->company_id);
            if ($locked->resolved_at !== null) throw new RuntimeException('Rework sudah diselesaikan.');
            $bundle = Bundle::withoutGlobalScopes()->where('company_id', $locked->company_id)->whereKey($locked->bundle_id)->lockForUpdate()->firstOrFail();
            $locked->update(['resolved_at'=>now(),'resolved_by'=>$user->id]);
            if (! ReworkRecord::withoutGlobalScopes()->where('bundle_id', $bundle->id)->whereNull('resolved_at')->exists()) {
                $bundle->update(['status'=>'ACTIVE']);
            }
            $this->audit->record('update', $locked, after: ['resolved_at'=>$locked->resolved_at]);
            return $locked->fresh(['reworkOrder','defect','operation']);
        });
    }

    private function assertAccess(User $user, int $companyId): void
    {
        if ((int) $user->company_id !== $companyId && ! $user->companies()->whereKey($companyId)->exists()) {
            throw new RuntimeException('User tidak memiliki akses ke company rework.');
        }
    }
}
