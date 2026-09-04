<?php

namespace Modules\Planning\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Support\CurrentCompany;
use Modules\MasterData\Models\Line;
use Modules\Planning\Models\LineLoading;
use Modules\Planning\Models\ProductionPlan;
use Modules\Production\Models\ProductionOrder;
use Modules\Sales\Models\SalesOrder;
use RuntimeException;

class ProductionPlanningService
{
    public function __construct(private AuditService $audit) {}

    public function createPlan(array $data, User $user): ProductionPlan
    {
        return DB::transaction(function () use ($data, $user): ProductionPlan {
            $companyId = $this->companyId();
            $this->access($user, $companyId);
            $this->assertPeriod($data['period_start'], $data['period_end']);

            $so = SalesOrder::withoutGlobalScopes()->where('company_id', $companyId)
                ->whereKey($data['sales_order_id'])->lockForUpdate()->firstOrFail();
            if ($so->status !== 'CONFIRMED') {
                throw new RuntimeException('Production Plan hanya dapat dibuat dari SO CONFIRMED.');
            }
            if (! $so->lines()->where('style_id', $data['style_id'])->exists()) {
                throw new RuntimeException('Style Production Plan tidak terdapat pada matrix SO.');
            }

            $line = $this->activeLine($companyId, (int) $data['line_id']);
            $duplicate = ProductionPlan::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('sales_order_id', $so->id)
                ->where('style_id', $data['style_id'])
                ->where('line_id', $line->id)
                ->whereDate('period_start', $data['period_start'])
                ->whereDate('period_end', $data['period_end'])
                ->exists();
            if ($duplicate) throw new RuntimeException('Production Plan untuk SO, style, line, dan periode tersebut sudah ada.');

            $plan = ProductionPlan::create([
                'company_id' => $companyId,
                'sales_order_id' => $so->id,
                'style_id' => $data['style_id'],
                'line_id' => $line->id,
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'target_qty' => $data['target_qty'],
                'created_by' => $user->id,
            ]);
            $this->audit->record('create', $plan, after: [
                'source' => 'CONFIRMED_SO_STYLE',
                'sales_order_id' => $so->id,
                'style_id' => (int) $data['style_id'],
                'line_id' => $line->id,
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'target_qty' => $data['target_qty'],
            ]);

            return $plan->fresh(['salesOrder', 'style', 'line']);
        });
    }

    public function updatePlan(ProductionPlan $plan, array $data, User $user): ProductionPlan
    {
        return DB::transaction(function () use ($plan, $data, $user): ProductionPlan {
            $locked = ProductionPlan::withoutGlobalScopes()->where('company_id', $plan->company_id)
                ->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $this->access($user, (int) $locked->company_id);
            $before = $locked->toArray();
            $periodStart = $data['period_start'] ?? $locked->period_start->toDateString();
            $periodEnd = $data['period_end'] ?? $locked->period_end->toDateString();
            $targetQty = (float) ($data['target_qty'] ?? $locked->target_qty);
            $lineId = (int) ($data['line_id'] ?? $locked->line_id);
            $this->assertPeriod($periodStart, $periodEnd);
            $this->activeLine((int) $locked->company_id, $lineId);

            $loadings = LineLoading::withoutGlobalScopes()->where('production_plan_id', $locked->id)->lockForUpdate()->get();
            if ($loadings->isNotEmpty() && $lineId !== (int) $locked->line_id) {
                throw new RuntimeException('Line Production Plan tidak dapat diganti setelah memiliki Line Loading.');
            }
            if ($loadings->contains(fn (LineLoading $row) => $row->plan_date->toDateString() < $periodStart || $row->plan_date->toDateString() > $periodEnd)) {
                throw new RuntimeException('Periode baru tidak mencakup seluruh Line Loading yang sudah tersimpan.');
            }
            if ((float) $loadings->sum('planned_qty') > $targetQty + 0.0001) {
                throw new RuntimeException('Target Production Plan tidak boleh lebih kecil dari total Line Loading.');
            }

            $locked->update([
                'line_id' => $lineId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'target_qty' => $targetQty,
                'updated_by' => $user->id,
            ]);
            $this->audit->record('update', $locked, before: $before, after: $locked->fresh()->toArray());

            return $locked->fresh(['salesOrder', 'style', 'line', 'loadings.productionOrder']);
        });
    }

    public function createLoading(ProductionPlan $plan, array $data, User $user): LineLoading
    {
        return DB::transaction(function () use ($plan, $data, $user): LineLoading {
            $lockedPlan = ProductionPlan::withoutGlobalScopes()->where('company_id', $plan->company_id)
                ->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $this->access($user, (int) $lockedPlan->company_id);
            $line = $this->activeLine((int) $lockedPlan->company_id, (int) $lockedPlan->line_id);
            $mo = ProductionOrder::withoutGlobalScopes()->where('company_id', $lockedPlan->company_id)
                ->whereKey($data['production_order_id'])->lockForUpdate()->firstOrFail();
            $this->assertLoadingSource($lockedPlan, $mo, $data['plan_date']);

            if (LineLoading::withoutGlobalScopes()->where('line_id', $line->id)
                ->whereDate('plan_date', $data['plan_date'])->where('production_order_id', $mo->id)->exists()) {
                throw new RuntimeException('MO tersebut sudah memiliki loading pada line dan tanggal yang sama.');
            }
            if (LineLoading::withoutGlobalScopes()->where('production_order_id', $mo->id)->where('line_id', '!=', $line->id)->exists()) {
                throw new RuntimeException('MO tidak dapat dipecah ke line berbeda karena header MO hanya memiliki satu line authority.');
            }
            $this->assertQuantityCeilings($lockedPlan, $mo, (float) $data['planned_qty']);

            $loading = LineLoading::create([
                'company_id' => $lockedPlan->company_id,
                'production_plan_id' => $lockedPlan->id,
                'line_id' => $line->id,
                'production_order_id' => $mo->id,
                'plan_date' => $data['plan_date'],
                'planned_qty' => $data['planned_qty'],
                'capacity_snapshot' => $line->capacity_std,
                'created_by' => $user->id,
            ]);
            $this->syncMoSchedule($mo, $user);
            $this->audit->record('create', $loading, after: [
                'production_plan_id' => $lockedPlan->id,
                'production_order_id' => $mo->id,
                'line_id' => $line->id,
                'plan_date' => $data['plan_date'],
                'planned_qty' => $data['planned_qty'],
                'capacity_snapshot' => $line->capacity_std,
                'capacity_policy' => 'REPORT_ONLY_NO_HARD_BLOCK',
            ]);

            return $loading->fresh(['productionPlan', 'line', 'productionOrder']);
        });
    }

    public function updateLoading(LineLoading $loading, array $data, User $user): LineLoading
    {
        return DB::transaction(function () use ($loading, $data, $user): LineLoading {
            $locked = LineLoading::withoutGlobalScopes()->where('company_id', $loading->company_id)
                ->whereKey($loading->id)->lockForUpdate()->firstOrFail();
            $plan = ProductionPlan::withoutGlobalScopes()->whereKey($locked->production_plan_id)->lockForUpdate()->firstOrFail();
            $mo = ProductionOrder::withoutGlobalScopes()->whereKey($locked->production_order_id)->lockForUpdate()->firstOrFail();
            $this->access($user, (int) $locked->company_id);
            if ($mo->status !== 'PLANNED') throw new RuntimeException('Line Loading hanya dapat diubah selama MO berstatus PLANNED.');

            $planDate = $data['plan_date'] ?? $locked->plan_date->toDateString();
            $plannedQty = (float) ($data['planned_qty'] ?? $locked->planned_qty);
            $this->assertLoadingSource($plan, $mo, $planDate);
            $duplicate = LineLoading::withoutGlobalScopes()->where('line_id', $locked->line_id)
                ->whereDate('plan_date', $planDate)->where('production_order_id', $mo->id)
                ->whereKeyNot($locked->id)->exists();
            if ($duplicate) throw new RuntimeException('MO tersebut sudah memiliki loading pada line dan tanggal yang sama.');
            $this->assertQuantityCeilings($plan, $mo, $plannedQty, $locked->id);

            $before = $locked->toArray();
            $line = $this->activeLine((int) $locked->company_id, (int) $locked->line_id);
            $locked->update([
                'plan_date' => $planDate,
                'planned_qty' => $plannedQty,
                'capacity_snapshot' => $line->capacity_std,
                'updated_by' => $user->id,
            ]);
            $this->syncMoSchedule($mo, $user);
            $this->audit->record('update', $locked, before: $before, after: $locked->fresh()->toArray());

            return $locked->fresh(['productionPlan', 'line', 'productionOrder']);
        });
    }

    public function capacitySummary(array $filters): Collection
    {
        $query = LineLoading::with('line.factory');
        if (! empty($filters['line_id'])) $query->where('line_id', $filters['line_id']);
        if (! empty($filters['from'])) $query->whereDate('plan_date', '>=', $filters['from']);
        if (! empty($filters['to'])) $query->whereDate('plan_date', '<=', $filters['to']);

        return $query->orderBy('plan_date')->orderBy('line_id')->get()
            ->groupBy(fn (LineLoading $row) => $row->line_id.'|'.$row->plan_date->toDateString())
            ->map(function (Collection $rows): array {
                $first = $rows->first();
                $planned = (float) $rows->sum('planned_qty');
                $capacity = $first->capacity_snapshot === null ? null : (float) $first->capacity_snapshot;
                return [
                    'line_id' => $first->line_id,
                    'line' => $first->line,
                    'plan_date' => $first->plan_date->toDateString(),
                    'planned_qty' => number_format($planned, 4, '.', ''),
                    'capacity_qty' => $capacity === null ? null : number_format($capacity, 4, '.', ''),
                    'variance_qty' => $capacity === null ? null : number_format($capacity - $planned, 4, '.', ''),
                    'load_pct' => $capacity && $capacity > 0 ? round(($planned / $capacity) * 100, 2) : null,
                    'is_overloaded' => $capacity !== null && $planned > $capacity + 0.0001,
                    'capacity_source' => 'LINE_CAPACITY_STD_SNAPSHOT',
                    'loading_count' => $rows->count(),
                ];
            })->values();
    }

    private function assertLoadingSource(ProductionPlan $plan, ProductionOrder $mo, string $planDate): void
    {
        if ($mo->status !== 'PLANNED') throw new RuntimeException('Hanya MO PLANNED yang dapat dijadwalkan.');
        if ((int) $mo->sales_order_id !== (int) $plan->sales_order_id || (int) $mo->style_id !== (int) $plan->style_id) {
            throw new RuntimeException('MO harus berasal dari SO dan style yang sama dengan Production Plan.');
        }
        if ($planDate < $plan->period_start->toDateString() || $planDate > $plan->period_end->toDateString()) {
            throw new RuntimeException('Tanggal Line Loading harus berada di dalam periode Production Plan.');
        }
        if ($mo->line_id !== null && (int) $mo->line_id !== (int) $plan->line_id) {
            throw new RuntimeException('MO sudah memiliki line authority yang berbeda.');
        }
    }

    private function assertQuantityCeilings(ProductionPlan $plan, ProductionOrder $mo, float $qty, ?int $exceptLoadingId = null): void
    {
        if ($qty <= 0) throw new RuntimeException('Planned quantity harus lebih besar dari nol.');
        $planQuery = LineLoading::withoutGlobalScopes()->where('production_plan_id', $plan->id);
        $moQuery = LineLoading::withoutGlobalScopes()->where('production_order_id', $mo->id);
        if ($exceptLoadingId !== null) {
            $planQuery->whereKeyNot($exceptLoadingId);
            $moQuery->whereKeyNot($exceptLoadingId);
        }
        if ((float) $planQuery->sum('planned_qty') + $qty > (float) $plan->target_qty + 0.0001) {
            throw new RuntimeException('Total Line Loading melebihi target Production Plan.');
        }
        if ((float) $moQuery->sum('planned_qty') + $qty > (float) $mo->qty_planned + 0.0001) {
            throw new RuntimeException('Total Line Loading melebihi qty planned MO.');
        }
    }

    private function syncMoSchedule(ProductionOrder $mo, User $user): void
    {
        $loadings = LineLoading::withoutGlobalScopes()->where('production_order_id', $mo->id)->orderBy('plan_date')->get();
        if ($loadings->isEmpty()) return;
        $lineIds = $loadings->pluck('line_id')->unique();
        if ($lineIds->count() !== 1) throw new RuntimeException('Line Loading MO memiliki lebih dari satu line authority.');
        $mo->update([
            'line_id' => $lineIds->first(),
            'planned_start' => $loadings->first()->plan_date->toDateString(),
            'planned_end' => $loadings->last()->plan_date->toDateString(),
            'updated_by' => $user->id,
        ]);
    }

    private function activeLine(int $companyId, int $lineId): Line
    {
        $line = Line::where('company_id', $companyId)->whereKey($lineId)->where('is_active', true)->lockForUpdate()->first();
        if (! $line) throw new RuntimeException('Line aktif tidak ditemukan pada company aktif.');
        return $line;
    }

    private function assertPeriod(string $start, string $end): void
    {
        if ($end < $start) throw new RuntimeException('Period end harus sama atau setelah period start.');
    }

    private function companyId(): int
    {
        $companyId = CurrentCompany::id();
        if ($companyId === null) throw new RuntimeException('Company context tidak tersedia.');
        return $companyId;
    }

    private function access(User $user, int $companyId): void
    {
        if ((int) $user->company_id !== $companyId && ! $user->companies()->whereKey($companyId)->exists()) {
            throw new RuntimeException('User tidak memiliki akses ke company Production Plan.');
        }
    }
}
