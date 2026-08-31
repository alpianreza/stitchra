<?php

namespace Modules\Cutting\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Cutting\Models\CutOrder;
use Modules\Cutting\Models\CutOrderLine;
use Modules\Production\Models\ProductionOrder;
use Modules\Receiving\Models\FabricRoll;
use RuntimeException;

class CuttingService
{
    public function __construct(private NumberingService $numbering, private AuditService $audit) {}

    public function create(ProductionOrder $mo, array $lines, User $user): CutOrder
    {
        if ($lines === []) throw new RuntimeException('Cut order wajib punya minimal 1 line.');

        return DB::transaction(function () use ($mo, $lines, $user): CutOrder {
            $locked = ProductionOrder::withoutGlobalScopes()->whereKey($mo->id)->lockForUpdate()->firstOrFail();
            $this->assertUserCompany($user, (int) $locked->company_id);
            if (! in_array($locked->status, ['RELEASED', 'CUTTING'], true)) {
                throw new RuntimeException("Cut order hanya untuk MO RELEASED/CUTTING (status: {$locked->status}).");
            }

            $seen = [];
            foreach ($lines as $line) {
                $qty = (float) ($line['qty_cut'] ?? 0);
                if ($qty <= 0) throw new RuntimeException('Qty cut wajib lebih besar dari nol.');
                $key = ((int) $line['colorway_id']).':'.((int) $line['size_id']);
                if (isset($seen[$key])) throw new RuntimeException('Matrix cut order tidak boleh duplikat.');
                $seen[$key] = true;

                $ordered = (float) DB::table('sales_order_lines')
                    ->where('sales_order_id', $locked->sales_order_id)
                    ->where('style_id', $locked->style_id)
                    ->where('colorway_id', $line['colorway_id'])
                    ->where('size_id', $line['size_id'])->sum('qty');
                if ($ordered <= 0) throw new RuntimeException('Colorway/size cut tidak berasal dari matrix SO/MO.');
                $alreadyCut = (float) DB::table('cut_order_lines')
                    ->join('cut_orders', 'cut_orders.id', '=', 'cut_order_lines.cut_order_id')
                    ->where('cut_orders.production_order_id', $locked->id)
                    ->where('cut_orders.status', '<>', 'CANCELLED')
                    ->where('cut_order_lines.colorway_id', $line['colorway_id'])
                    ->where('cut_order_lines.size_id', $line['size_id'])->sum('cut_order_lines.qty_cut');
                if ($alreadyCut + $qty - $ordered > 0.0001) {
                    throw new RuntimeException("Qty cut melebihi qty order tersisa ({$ordered} ordered, {$alreadyCut} sudah dibuat).");
                }
            }

            $cutOrder = CutOrder::create([
                'company_id' => $locked->company_id,
                'doc_no' => $this->numbering->next($locked->company_id, 'CUT'),
                'production_order_id' => $locked->id, 'cut_date' => now()->toDateString(),
                'status' => 'IN_PROGRESS', 'created_by' => $user->id,
            ]);
            foreach ($lines as $line) $cutOrder->lines()->create($line);
            if ($locked->status === 'RELEASED') {
                $locked->update(['status' => 'CUTTING', 'actual_start' => now()->toDateString(), 'updated_by' => $user->id]);
            }
            $this->audit->record('create', $cutOrder, after: ['doc_no' => $cutOrder->doc_no, 'mo' => $locked->doc_no]);
            return $cutOrder->load('lines');
        });
    }

    public function recordMarker(CutOrder $cutOrder, array $markers, User $user): CutOrder
    {
        if ($markers === []) throw new RuntimeException('Marker wajib punya minimal 1 line.');

        return DB::transaction(function () use ($cutOrder, $markers, $user): CutOrder {
            $locked = CutOrder::withoutGlobalScopes()->whereKey($cutOrder->id)->lockForUpdate()->firstOrFail();
            $this->assertUserCompany($user, (int) $locked->company_id);
            if ($locked->status !== 'IN_PROGRESS') throw new RuntimeException('Marker hanya dapat dicatat pada cut order IN_PROGRESS.');
            $mo = ProductionOrder::withoutGlobalScopes()->with('bomVersion.lines.material')->whereKey($locked->production_order_id)->lockForUpdate()->firstOrFail();

            foreach ($markers as $marker) {
                $used = (float) ($marker['qty_fabric_used_m'] ?? 0);
                if ($used <= 0 || (float) ($marker['marker_length_m'] ?? 0) <= 0 || (int) ($marker['plies'] ?? 0) <= 0) {
                    throw new RuntimeException('Marker length, plies, dan fabric used wajib lebih besar dari nol.');
                }
                $roll = FabricRoll::withoutGlobalScopes()->where('company_id', $locked->company_id)
                    ->whereKey((int) $marker['roll_id'])->lockForUpdate()->first();
                if ($roll === null || ! in_array($roll->status, ['RELEASED'], true)) {
                    throw new RuntimeException('Roll marker tidak valid atau tidak RELEASED.');
                }
                $isBomFabric = $mo->bomVersion->lines->contains(fn ($line) => (int) $line->material_id === (int) $roll->material_id && $line->material?->type === 'FABRIC');
                if (! $isBomFabric) throw new RuntimeException('Roll marker bukan material fabric BOM snapshot MO.');

                $issued = (float) DB::table('material_issue_lines')
                    ->join('material_issues', 'material_issues.id', '=', 'material_issue_lines.material_issue_id')
                    ->where('material_issues.production_order_id', $mo->id)
                    ->where('material_issue_lines.roll_id', $roll->id)->sum('material_issue_lines.qty');
                $marked = (float) DB::table('marker_logs')
                    ->join('cut_orders', 'cut_orders.id', '=', 'marker_logs.cut_order_id')
                    ->where('cut_orders.production_order_id', $mo->id)
                    ->where('marker_logs.roll_id', $roll->id)->sum('marker_logs.qty_fabric_used_m');
                if ($marked + $used - $issued > 0.0001) {
                    throw new RuntimeException("Pemakaian marker melebihi fabric yang sudah di-issue untuk roll {$roll->roll_no}.");
                }
                if ((float) $roll->qty_remaining_meter + 0.0001 < $used) {
                    throw new RuntimeException('Pemakaian marker melebihi sisa fisik roll.');
                }

                $locked->markerLogs()->create([
                    'roll_id' => $roll->id, 'marker_length_m' => $marker['marker_length_m'],
                    'plies' => $marker['plies'], 'qty_fabric_used_m' => $used,
                    'efficiency_pct' => $marker['efficiency_pct'] ?? null, 'created_by' => $user->id,
                ]);
                $roll->consume($used);
            }
            $this->audit->record('update', $locked, after: ['markers' => count($markers)]);
            return $locked->load('markerLogs');
        });
    }

    public function generateBundles(CutOrder $cutOrder, int $cutOrderLineId, int $bundleSize, User $user): array
    {
        if ($bundleSize <= 0) throw new RuntimeException('Bundle size harus > 0.');

        return DB::transaction(function () use ($cutOrder, $cutOrderLineId, $bundleSize, $user): array {
            $locked = CutOrder::withoutGlobalScopes()->whereKey($cutOrder->id)->lockForUpdate()->firstOrFail();
            $this->assertUserCompany($user, (int) $locked->company_id);
            if ($locked->status !== 'IN_PROGRESS') throw new RuntimeException('Bundles hanya dapat dibuat untuk cut order IN_PROGRESS.');
            $line = CutOrderLine::query()->where('cut_order_id', $locked->id)->whereKey($cutOrderLineId)->lockForUpdate()->firstOrFail();
            if ($line->bundles()->exists()) throw new RuntimeException('Bundles untuk line ini sudah digenerate.');

            $remaining = (float) $line->qty_cut; $seq = 0; $bundles = [];
            while ($remaining > 0.0001) {
                $seq++; $qty = min((float) $bundleSize, $remaining);
                $bundles[] = $line->bundles()->create([
                    'company_id' => $locked->company_id,
                    'bundle_no' => $locked->doc_no.'-L'.$line->id.'-B'.str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
                    'production_order_id' => $locked->production_order_id,
                    'qty' => $qty, 'current_stage' => 'CUTTING', 'status' => 'ACTIVE',
                ]);
                $remaining = round($remaining - $qty, 4);
            }
            return $bundles;
        });
    }

    public function complete(CutOrder $cutOrder, User $user): CutOrder
    {
        return DB::transaction(function () use ($cutOrder, $user): CutOrder {
            $locked = CutOrder::withoutGlobalScopes()->with('lines.bundles')->whereKey($cutOrder->id)->lockForUpdate()->firstOrFail();
            $this->assertUserCompany($user, (int) $locked->company_id);
            if ($locked->status !== 'IN_PROGRESS') throw new RuntimeException('Hanya cut order IN_PROGRESS yang dapat diselesaikan.');
            foreach ($locked->lines as $line) {
                if ($line->bundles->isEmpty() || abs((float) $line->bundles->sum('qty') - (float) $line->qty_cut) > 0.0001) {
                    throw new RuntimeException('Seluruh cut line wajib memiliki bundle dengan total qty yang sama dengan qty_cut.');
                }
            }

            $mo = ProductionOrder::withoutGlobalScopes()->whereKey($locked->production_order_id)->lockForUpdate()->firstOrFail();
            $usageByMaterial = DB::table('marker_logs')->join('fabric_rolls', 'fabric_rolls.id', '=', 'marker_logs.roll_id')
                ->where('marker_logs.cut_order_id', $locked->id)
                ->selectRaw('fabric_rolls.material_id, SUM(marker_logs.qty_fabric_used_m) as used')
                ->groupBy('fabric_rolls.material_id')->get();
            $totalCut = (float) $locked->lines->sum('qty_cut');
            foreach ($usageByMaterial as $usage) {
                $allocation = $mo->materialAllocations()->where('material_id', $usage->material_id)->lockForUpdate()->firstOrFail();
                $allocation->qty_consumed = (float) $allocation->qty_consumed + (float) $usage->used;
                $allocation->actual_consumption_per_pcs = $totalCut > 0 ? round((float) $usage->used / $totalCut, 6) : null;
                $allocation->save();
            }
            $locked->update(['status' => 'COMPLETED', 'updated_by' => $user->id]);
            $this->audit->record('update', $locked, after: ['status' => 'COMPLETED']);
            return $locked->fresh(['lines', 'markerLogs']);
        });
    }

    private function assertUserCompany(User $user, int $companyId): void
    {
        if ((int) $user->company_id !== $companyId && ! $user->companies()->whereKey($companyId)->exists()) {
            throw new RuntimeException('User tidak memiliki akses ke company cutting document.');
        }
    }
}
