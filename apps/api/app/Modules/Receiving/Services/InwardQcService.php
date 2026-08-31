<?php

namespace Modules\Receiving\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\NumberingService;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\Receiving\Models\FabricRoll;
use Modules\Receiving\Models\GoodsReceipt;
use Modules\Receiving\Models\GrLine;
use Modules\Receiving\Models\InwardInspection;
use RuntimeException;

class InwardQcService
{
    public function __construct(private NumberingService $numbering, private InventoryTransactionService $its) {}

    public function create(int $companyId, GoodsReceipt $gr, array $lines, User $user): InwardInspection
    {
        if ($lines === []) throw new RuntimeException('Inspeksi wajib punya minimal 1 line.');
        if ((int) $gr->company_id !== $companyId || $gr->status !== 'POSTED') throw new RuntimeException('Inspeksi hanya dapat dibuat untuk GR POSTED pada company aktif.');

        return DB::transaction(function () use ($companyId, $gr, $lines, $user): InwardInspection {
            $inspection = InwardInspection::create([
                'company_id' => $companyId, 'doc_no' => $this->numbering->next($companyId, 'FQC'),
                'goods_receipt_id' => $gr->id, 'result' => 'PENDING', 'created_by' => $user->id,
            ]);
            $seen = []; $results = [];
            foreach ($lines as $line) {
                $grLine = GrLine::query()->where('goods_receipt_id', $gr->id)->whereKey((int) $line['gr_line_id'])->first();
                if ($grLine === null) throw new RuntimeException('Inspection line tidak berasal dari GR yang dipilih.');
                $rollId = ! empty($line['roll_id']) ? (int) $line['roll_id'] : null;
                if ($grLine->rolls()->exists()) {
                    if ($rollId === null || ! $grLine->rolls()->whereKey($rollId)->exists()) throw new RuntimeException('Fabric inspection wajib menunjuk roll pada GR line yang sama.');
                } elseif ($rollId !== null) throw new RuntimeException('Roll tidak valid untuk GR line non-roll.');
                $key = $grLine->id.':'.($rollId ?? 'none');
                if (isset($seen[$key])) throw new RuntimeException('Inspection line duplikat.');
                $seen[$key] = true;
                $inspection->lines()->create($line); $results[] = $line['result'];
            }
            $inspection->result = match (true) {
                in_array('FAIL', $results, true) && in_array('PASS', $results, true) => 'PARTIAL',
                in_array('FAIL', $results, true) => 'FAIL', default => 'PASS',
            };
            $inspection->save();
            return $inspection->load('lines');
        });
    }

    public function finalize(InwardInspection $inspection, array $ignoredClientLines, User $user): void
    {
        DB::transaction(function () use ($inspection, $user): void {
            $locked = InwardInspection::withoutGlobalScopes()->whereKey($inspection->id)->lockForUpdate()->firstOrFail();
            if ($locked->finalized_at !== null) return;
            $gr = GoodsReceipt::withoutGlobalScopes()->where('company_id', $locked->company_id)->whereKey($locked->goods_receipt_id)->firstOrFail();
            $affected = [];
            foreach ($locked->lines()->lockForUpdate()->get() as $inspectionLine) {
                $grLine = GrLine::query()->where('goods_receipt_id', $gr->id)->whereKey($inspectionLine->gr_line_id)->lockForUpdate()->firstOrFail();
                $affected[$grLine->id] = true;
                $material = $grLine->material()->withoutGlobalScopes()->firstOrFail();
                $roll = null; $qty = (float) $grLine->qty_received; $lotNo = null; $uomId = $grLine->uom_id;
                if ($inspectionLine->roll_id !== null) {
                    $roll = FabricRoll::withoutGlobalScopes()->where('gr_line_id', $grLine->id)->whereKey($inspectionLine->roll_id)->lockForUpdate()->firstOrFail();
                    $qty = (float) $roll->qty_meter_actual; $lotNo = $roll->lot_no; $uomId = $material->use_uom_id;
                    if (! $uomId) throw new RuntimeException('Use UOM fabric tidak tersedia.');
                }
                if ($inspectionLine->result === 'PASS') {
                    $this->its->releaseQualityHold((int) $locked->company_id, [
                        'material_id' => $grLine->material_id, 'warehouse_id' => $gr->warehouse_id,
                        'lot_no' => $lotNo, 'roll_id' => $roll?->id, 'uom_id' => $uomId,
                        'source_document_type' => 'inward_inspections', 'source_document_id' => $locked->id,
                        'source_document_line_id' => $inspectionLine->id,
                    ], $qty, $user);
                    $roll?->update(['status' => 'RELEASED']);
                } else $roll?->update(['status' => 'REJECTED_RETURNED']);
            }
            foreach (array_keys($affected) as $grLineId) {
                $grLine = GrLine::query()->with('rolls')->findOrFail($grLineId);
                if ($grLine->rolls->isEmpty()) {
                    $result = $locked->lines()->where('gr_line_id', $grLineId)->value('result');
                    $grLine->update(['status' => $result === 'PASS' ? 'RELEASED' : 'REJECTED_RETURNED']);
                } else {
                    $statuses = $grLine->rolls->pluck('status')->unique();
                    $status = $statuses->count() === 1 ? ($statuses->first() === 'RELEASED' ? 'RELEASED' : 'REJECTED_RETURNED') : 'PARTIAL';
                    $grLine->update(['status' => $status]);
                }
            }
            $locked->update(['finalized_at' => now(), 'updated_by' => $user->id]);
        });
    }

    public function returnGoods(int $companyId, GoodsReceipt $gr, array $returnLines, string $reason, User $user): void
    {
        if ($returnLines === []) throw new RuntimeException('Supplier return wajib punya minimal 1 line.');
        DB::transaction(function () use ($companyId, $gr, $returnLines, $reason, $user): void {
            if ((int) $gr->company_id !== $companyId || $gr->status !== 'POSTED') throw new RuntimeException('Supplier return harus berasal dari GR POSTED pada company aktif.');
            $return = $gr->purchaseOrder->supplier->returns()->create([
                'company_id' => $companyId, 'doc_no' => $this->numbering->next($companyId, 'GR'),
                'goods_receipt_id' => $gr->id, 'reason' => $reason, 'status' => 'SUBMITTED', 'created_by' => $user->id,
            ]);
            $itsLines = [];
            foreach ($returnLines as $input) {
                $grLine = GrLine::query()->where('goods_receipt_id', $gr->id)->whereKey((int) ($input['gr_line_id'] ?? 0))->first();
                if ($grLine === null) throw new RuntimeException('Return line tidak berasal dari GR yang dipilih.');
                $material = $grLine->material()->withoutGlobalScopes()->firstOrFail();
                $roll = null; $qty = (float) $grLine->qty_received; $lotNo = null; $uomId = $grLine->uom_id; $unitCost = (float) $grLine->unit_price;
                if (! empty($input['roll_id'])) {
                    $roll = FabricRoll::withoutGlobalScopes()->where('gr_line_id', $grLine->id)->whereKey((int) $input['roll_id'])->first();
                    if ($roll === null || $roll->status !== 'REJECTED_RETURNED') throw new RuntimeException('Hanya roll rejected dari GR line yang sama yang dapat diretur.');
                    $qty = (float) $roll->qty_meter_actual; $lotNo = $roll->lot_no; $uomId = $material->use_uom_id;
                    $unitCost = round(((float) $grLine->unit_price * (float) $roll->qty_buy) / $qty, 6);
                } elseif ($grLine->status !== 'REJECTED_RETURNED') throw new RuntimeException('Hanya GR line rejected yang dapat diretur.');
                $itsLines[] = [
                    'material_id' => $grLine->material_id, 'warehouse_id' => $gr->warehouse_id,
                    'lot_no' => $lotNo, 'roll_id' => $roll?->id, 'qty' => $qty,
                    'uom_id' => $uomId, 'unit_cost' => $unitCost, 'source_document_line_id' => $grLine->id,
                ];
            }
            $this->its->post('PURCHASE_RETURN', [
                'company_id' => $companyId, 'source_document_type' => 'supplier_returns', 'source_document_id' => $return->id,
            ], $itsLines, $user);
        });
    }
}
