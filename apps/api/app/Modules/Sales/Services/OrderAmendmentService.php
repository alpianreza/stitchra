<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Planning\Models\MrpRequirement;
use Modules\Planning\Services\MrpService;
use Modules\Sales\Models\OrderAmendment;
use Modules\Sales\Models\SalesOrder;
use RuntimeException;

class OrderAmendmentService
{
    public function __construct(
        private NumberingService $numbering,
        private AuditService $audit,
        private MrpService $mrp,
        private SalesOrderService $salesOrders,
    ) {}

    public function createDraft(SalesOrder $salesOrder, array $data, User $user): OrderAmendment
    {
        return DB::transaction(function () use ($salesOrder, $data, $user): OrderAmendment {
            $so = SalesOrder::withoutGlobalScopes()->with('lines')->whereKey($salesOrder->id)->lockForUpdate()->firstOrFail();
            $this->access($user, (int) $so->company_id);
            $this->assertAmendable($so);

            $requested = collect($data['lines'] ?? [])->keyBy(fn (array $line) => (int) $line['sales_order_line_id']);
            if ($requested->count() !== count($data['lines'] ?? [])) throw new RuntimeException('Sales Order line amendment tidak boleh duplikat.');
            $changes = [];
            foreach ($requested as $lineId => $change) {
                $line = $so->lines->firstWhere('id', $lineId);
                if (! $line) throw new RuntimeException('Amendment line tidak berasal dari Sales Order terkait.');
                $newQty = round((float) $change['new_qty'], 4);
                if ($newQty <= 0) throw new RuntimeException('Amendment quantity wajib lebih besar dari nol.');
                $oldQty = (float) $line->qty;
                if (abs($newQty - $oldQty) <= 0.0001) continue;
                $changes[] = ['sales_order_line_id' => (int) $line->id, 'old_qty' => $oldQty, 'new_qty' => $newQty, 'qty_delta' => round($newQty - $oldQty, 4)];
            }

            $oldDate = $so->ex_factory_date?->toDateString();
            $newDate = $data['new_ex_factory_date'] ?? $oldDate;
            if ($changes === [] && $newDate === $oldDate) throw new RuntimeException('Amendment harus mengubah quantity atau ex-factory date.');

            $amendment = OrderAmendment::create([
                'company_id' => $so->company_id,
                'doc_no' => $this->numbering->next((int) $so->company_id, 'SO'),
                'sales_order_id' => $so->id,
                'line_delta' => $changes,
                'reason' => trim($data['reason']),
                'old_ex_factory_date' => $oldDate,
                'new_ex_factory_date' => $newDate,
                'status' => 'DRAFT',
                'created_by' => $user->id,
            ]);
            foreach ($changes as $change) $amendment->lines()->create($change + ['company_id' => $so->company_id, 'created_by' => $user->id]);
            $this->audit->record('create', $amendment, after: ['doc_no' => $amendment->doc_no, 'sales_order_id' => $so->id, 'qty_changes' => count($changes), 'date_change' => $newDate !== $oldDate]);
            return $amendment->fresh(['salesOrder', 'lines.salesOrderLine']);
        });
    }

    public function apply(OrderAmendment $amendment, User $user): OrderAmendment
    {
        return DB::transaction(function () use ($amendment, $user): OrderAmendment {
            $locked = OrderAmendment::withoutGlobalScopes()->with(['salesOrder', 'lines.salesOrderLine'])->whereKey($amendment->id)->lockForUpdate()->firstOrFail();
            $this->access($user, (int) $locked->company_id);
            if ($locked->status !== 'DRAFT') throw new RuntimeException('Hanya amendment DRAFT yang dapat diterapkan.');
            $so = SalesOrder::withoutGlobalScopes()->whereKey($locked->sales_order_id)->lockForUpdate()->firstOrFail();
            $this->assertAmendable($so);
            foreach ($locked->lines as $line) {
                $current = (float) $line->salesOrderLine()->lockForUpdate()->value('qty');
                if (abs($current - (float) $line->old_qty) > 0.0001) throw new RuntimeException('AMENDMENT_CONFLICT: quantity SO berubah sejak draft dibuat.');
            }
            if ($so->ex_factory_date?->toDateString() !== $locked->old_ex_factory_date?->toDateString()) throw new RuntimeException('AMENDMENT_CONFLICT: ex-factory date berubah sejak draft dibuat.');

            $baseline = $this->mrp->run((int) $so->company_id, ['so_ids' => [(int) $so->id]], $user);
            $baseline->update(['run_type' => 'AMENDMENT_BASELINE', 'source_amendment_id' => $locked->id]);

            foreach ($locked->lines as $line) $line->salesOrderLine()->update(['qty' => $line->new_qty]);
            if ($locked->new_ex_factory_date?->toDateString() !== $locked->old_ex_factory_date?->toDateString()) {
                $so->update(['ex_factory_date' => $locked->new_ex_factory_date, 'updated_by' => $user->id]);
            }

            $delta = $this->mrp->run((int) $so->company_id, ['so_ids' => [(int) $so->id]], $user);
            $delta->update(['run_type' => 'AMENDMENT_DELTA', 'source_amendment_id' => $locked->id, 'baseline_mrp_run_id' => $baseline->id]);
            $baselineByMaterial = $baseline->requirements()->get()->keyBy('material_id');
            foreach ($delta->requirements()->get() as $requirement) {
                $before = $baselineByMaterial->get($requirement->material_id);
                $requirement->update([
                    'baseline_gross_qty' => $before?->gross_qty ?? 0,
                    'gross_delta_qty' => round((float) $requirement->gross_qty - (float) ($before?->gross_qty ?? 0), 4),
                    'baseline_net_qty' => $before?->net_qty ?? 0,
                    'net_delta_qty' => round((float) $requirement->net_qty - (float) ($before?->net_qty ?? 0), 4),
                ]);
            }

            $locked->update(['status' => 'APPROVED', 'baseline_mrp_run_id' => $baseline->id, 'delta_mrp_run_id' => $delta->id, 'applied_at' => now(), 'applied_by' => $user->id, 'updated_by' => $user->id]);
            $this->audit->record('apply', $locked, before: ['status' => 'DRAFT'], after: ['status' => 'APPROVED', 'baseline_mrp_run_id' => $baseline->id, 'delta_mrp_run_id' => $delta->id, 'so_status' => 'CONFIRMED']);
            return $locked->fresh(['salesOrder', 'lines.salesOrderLine.style', 'lines.salesOrderLine.colorway.color', 'lines.salesOrderLine.size', 'baselineMrpRun.requirements.material', 'deltaMrpRun.requirements.material']);
        });
    }

    private function assertAmendable(SalesOrder $so): void
    {
        if ($so->status !== 'CONFIRMED') throw new RuntimeException('Amendment hanya untuk Sales Order CONFIRMED.');
        if ($this->salesOrders->cuttingStarted($so)) throw new RuntimeException('BR-022 BLOCK: amendment terkunci karena cutting sudah dimulai.');
    }

    private function access(User $user, int $companyId): void
    {
        if ((int) $user->company_id !== $companyId && ! $user->companies()->whereKey($companyId)->exists()) throw new RuntimeException('User tidak memiliki akses ke company amendment.');
    }
}
