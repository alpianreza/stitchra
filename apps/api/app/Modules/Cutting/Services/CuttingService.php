<?php

namespace Modules\Cutting\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Cutting\Models\CutOrder;
use Modules\Cutting\Models\CutOrderLine;
use Modules\Production\Models\ProductionOrder;
use RuntimeException;

class CuttingService
{
    public function __construct(private NumberingService $numbering, private AuditService $audit) {}

    public function create(ProductionOrder $mo, array $lines, User $user, ?int $cutPlanId = null): CutOrder
    {
        return DB::transaction(function () use ($mo, $lines, $user, $cutPlanId): CutOrder {
            if ($lines === []) throw new RuntimeException('Cut order wajib punya minimal 1 line.');
            $locked = ProductionOrder::withoutGlobalScopes()->whereKey($mo->id)->lockForUpdate()->firstOrFail(); $this->access($user, (int) $locked->company_id);
            if (! in_array($locked->status, ['RELEASED', 'CUTTING'], true)) throw new RuntimeException('Status MO tidak mengizinkan cut order.');
            if ($cutPlanId !== null && ! DB::table('cut_plans')->where('id', $cutPlanId)->where('company_id', $locked->company_id)->where('production_order_id', $locked->id)->exists()) throw new RuntimeException('Cut Plan tidak valid untuk MO.');
            $hasMoMatrix = DB::table('mo_lines')->where('production_order_id', $locked->id)->exists();
            $seen = [];
            foreach ($lines as $line) { $qty = (float) ($line['qty_cut'] ?? 0); if ($qty <= 0) throw new RuntimeException('Qty cut wajib lebih besar dari nol.');
                $key = (int) $line['colorway_id'].':'.(int) $line['size_id']; if (isset($seen[$key])) throw new RuntimeException('Matrix cut order tidak boleh duplikat.'); $seen[$key] = true;
                $ordered = $hasMoMatrix
                    ? (float) DB::table('mo_lines')->where('production_order_id', $locked->id)->where('colorway_id', $line['colorway_id'])->where('size_id', $line['size_id'])->sum('qty_planned')
                    : (float) DB::table('sales_order_lines')->where('sales_order_id', $locked->sales_order_id)->where('style_id', $locked->style_id)->where('colorway_id', $line['colorway_id'])->where('size_id', $line['size_id'])->sum('qty');
                $cut = (float) DB::table('cut_order_lines')->join('cut_orders', 'cut_orders.id', '=', 'cut_order_lines.cut_order_id')
                    ->where('cut_orders.production_order_id', $locked->id)->where('cut_orders.status', '<>', 'CANCELLED')
                    ->where('cut_order_lines.colorway_id', $line['colorway_id'])->where('cut_order_lines.size_id', $line['size_id'])->sum('cut_order_lines.qty_cut');
                if ($ordered <= 0 || $cut + $qty - $ordered > 0.0001) throw new RuntimeException($hasMoMatrix ? 'Qty cut melebihi matrix MO.' : 'Qty cut melebihi legacy matrix SO.'); }
            $cutOrder = CutOrder::create(['company_id' => $locked->company_id, 'doc_no' => $this->numbering->next($locked->company_id, 'CUT'),
                'production_order_id' => $locked->id, 'cut_plan_id' => $cutPlanId, 'cut_date' => now()->toDateString(), 'status' => 'IN_PROGRESS', 'created_by' => $user->id]);
            foreach ($lines as $line) $cutOrder->lines()->create($line);
            if ($locked->status === 'RELEASED') $locked->update(['status' => 'CUTTING', 'actual_start' => now()->toDateString(), 'updated_by' => $user->id]);
            $this->audit->record('create', $cutOrder, after: ['doc_no' => $cutOrder->doc_no, 'mo' => $locked->doc_no, 'cut_plan_id' => $cutPlanId, 'planning_source' => $cutPlanId ? 'CUT_PLAN' : 'LEGACY_DIRECT_MO', 'matrix_source' => $hasMoMatrix ? 'MO_LINES' : 'LEGACY_SO_LINES']); return $cutOrder->load('lines');
        });
    }

    /** Legacy endpoint retained for compatibility, but new Marker consumption writes are prohibited by D-01. */
    public function recordMarker(CutOrder $cutOrder, array $markers, User $user): CutOrder
    {
        $locked = CutOrder::withoutGlobalScopes()->whereKey($cutOrder->id)->firstOrFail(); $this->access($user, (int) $locked->company_id);
        throw new RuntimeException('BR-031: Marker adalah legacy read-only evidence; actual fabric consumption baru wajib melalui Lay Roll.');
    }

    public function generateBundles(CutOrder $cutOrder, int $lineId, int $size, User $user): array
    {
        return DB::transaction(function () use ($cutOrder, $lineId, $size, $user): array {
            if ($size <= 0) throw new RuntimeException('Bundle size harus > 0.');
            $locked = CutOrder::withoutGlobalScopes()->whereKey($cutOrder->id)->lockForUpdate()->firstOrFail(); $this->access($user, (int) $locked->company_id);
            if ($locked->status !== 'IN_PROGRESS') throw new RuntimeException('Bundles hanya untuk cut order IN_PROGRESS.');
            $line = CutOrderLine::query()->where('cut_order_id', $locked->id)->whereKey($lineId)->lockForUpdate()->firstOrFail();
            if ($line->bundles()->exists()) throw new RuntimeException('Bundles untuk line ini sudah digenerate.');
            $remaining = (float) $line->qty_cut; $sequence = 0; $output = [];
            while ($remaining > 0.0001) { $sequence++; $qty = min((float) $size, $remaining); $output[] = $line->bundles()->create([
                'company_id' => $locked->company_id, 'bundle_no' => $locked->doc_no.'-L'.$line->id.'-B'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
                'production_order_id' => $locked->production_order_id, 'qty' => $qty, 'current_stage' => 'CUTTING', 'status' => 'ACTIVE']); $remaining = round($remaining - $qty, 4); }
            return $output;
        });
    }

    public function complete(CutOrder $cutOrder, User $user): CutOrder
    {
        return DB::transaction(function () use ($cutOrder, $user): CutOrder {
            $locked = CutOrder::withoutGlobalScopes()->with('lines.bundles')->whereKey($cutOrder->id)->lockForUpdate()->firstOrFail(); $this->access($user, (int) $locked->company_id);
            if ($locked->status !== 'IN_PROGRESS') throw new RuntimeException('Hanya cut order IN_PROGRESS yang dapat diselesaikan.');
            if ($locked->markerLogs()->exists()) throw new RuntimeException('BR-031/067: Cut Order dengan Marker legacy tidak boleh menghasilkan actual-consumption mutation baru.');
            foreach ($locked->lines as $line) if ($line->bundles->isEmpty() || abs((float) $line->bundles->sum('qty') - (float) $line->qty_cut) > 0.0001) throw new RuntimeException('Seluruh cut line wajib memiliki bundle dengan total tepat.');
            $locked->update(['status' => 'COMPLETED', 'updated_by' => $user->id]); $this->audit->record('update', $locked, after: ['status' => 'COMPLETED', 'consumption_path' => 'NONE_OR_LAY_ROLL']);
            return $locked->fresh(['lines', 'markerLogs']);
        });
    }

    private function access(User $user, int $companyId): void { if ((int) $user->company_id !== $companyId && ! $user->companies()->whereKey($companyId)->exists()) throw new RuntimeException('User tidak memiliki akses ke company cutting document.'); }
}
