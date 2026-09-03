<?php

namespace Modules\Finance\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Finance\Models\ActualCostFreeze;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Services\NamedProductionMeasureService;
use RuntimeException;

/** Serializes event-driven D-07 operations around the authoritative MO. */
class ManufacturingValuationWorkflow
{
    public function __construct(
        private ManufacturingValuationService $valuation,
        private NamedProductionMeasureService $measures,
    ) {}

    public function valueFgReceipt(ProductionOrder $mo, int $movementId, User $user): array
    {
        return DB::transaction(function () use ($mo, $movementId, $user): array {
            $locked = ProductionOrder::withoutGlobalScopes()->whereKey($mo->id)->lockForUpdate()->firstOrFail();
            $this->access($user, (int) $locked->company_id);

            $existing = DB::table('fg_valuation_events')
                ->where('company_id', $locked->company_id)
                ->where('production_order_id', $locked->id)
                ->where('stock_movement_id', $movementId)
                ->orderBy('component')
                ->get();

            if ($existing->count() === count(ManufacturingValuationService::COMPONENTS)) {
                return ['status' => 'VALUED_REPLAY', 'events' => $existing->map(fn ($row) => (array) $row)->all()];
            }
            if ($existing->isNotEmpty()) {
                throw new RuntimeException('CONFLICT: incomplete FG valuation identity set.');
            }

            return $this->valuation->valueFgReceipt($locked, $movementId, $user);
        });
    }

    public function createFreeze(ProductionOrder $mo, string $period, ?float $otherAmount, ?string $otherSource, User $user): ActualCostFreeze
    {
        return DB::transaction(function () use ($mo, $period, $otherAmount, $otherSource, $user): ActualCostFreeze {
            $locked = ProductionOrder::withoutGlobalScopes()->whereKey($mo->id)->lockForUpdate()->firstOrFail();
            $this->access($user, (int) $locked->company_id);

            $pending = ActualCostFreeze::withoutGlobalScopes()
                ->where('company_id', $locked->company_id)
                ->where('production_order_id', $locked->id)
                ->where('period', $period)
                ->where('status', 'PENDING')
                ->lockForUpdate()
                ->latest('freeze_version')
                ->first();

            if ($pending) {
                $sameOther = round((float) ($pending->component_amounts['OTHER'] ?? 0), 4) === round((float) ($otherAmount ?? 0), 4);
                $sameSource = (string) ($pending->source_evidence['other'] ?? 'STANDARD_OTHER_ZERO') === (string) ($otherSource ?? 'STANDARD_OTHER_ZERO');
                if (! $sameOther || ! $sameSource) {
                    throw new RuntimeException('CONFLICT: pending actual-cost freeze has different source evidence.');
                }
                return $pending;
            }

            $measure = $this->measures->measure($locked, 'FG_RECEIVED_QTY');
            if (($measure['status'] ?? null) !== 'DEFINED' || $measure['qty'] === null) {
                throw new RuntimeException('FAIL_CLOSED: D-09 FG_RECEIVED_QTY denominator is missing.');
            }
            $denominator = (float) $measure['qty'];
            $valued = (float) DB::table('fg_valuation_events')
                ->where('company_id', $locked->company_id)
                ->where('production_order_id', $locked->id)
                ->where('component', 'FABRIC')
                ->sum('receipt_quantity');
            if (abs($denominator - $valued) > 0.0001) {
                throw new RuntimeException('FAIL_CLOSED: all authoritative FG receipts must be valued before actual-cost freeze.');
            }

            return $this->valuation->createFreeze($locked, $period, $otherAmount, $otherSource, $user);
        });
    }

    private function access(User $user, int $companyId): void
    {
        if ((int) $user->company_id !== $companyId && ! $user->companies()->whereKey($companyId)->exists()) {
            throw new RuntimeException('User does not have access to the valuation company.');
        }
    }
}
