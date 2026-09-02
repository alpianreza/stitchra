<?php

namespace Modules\Packing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\MasterData\Models\Uom;
use Modules\Packing\Models\Carton;
use Modules\Packing\Models\PackingList;
use Modules\Production\Models\ProductionOrder;
use Modules\Qc\Models\QcInspection;
use Modules\Sales\Models\SalesOrder;
use RuntimeException;

class PackingService
{
    public function __construct(
        private NumberingService $numbering,
        private InventoryTransactionService $its,
        private AuditService $audit,
    ) {}

    public function eligiblePackingInputs(int $companyId): array
    {
        $passes = QcInspection::withoutGlobalScopes()
            ->with(['productionOrder.salesOrder', 'productionOrder.style'])
            ->where('company_id', $companyId)
            ->where('stage', 'FINAL')->where('verdict', 'PASS')
            ->orderByDesc('cycle')->orderByDesc('id')->get();

        $seen = [];
        $eligible = [];
        foreach ($passes as $pass) {
            $mo = $pass->productionOrder;
            if ($mo === null || $mo->status !== 'QC' || isset($seen[$mo->id])) continue;
            $seen[$mo->id] = true;
            $packed = $this->packedQuantityForMo((int)$mo->id);
            $remaining = max(0.0, (float)$pass->lot_qty - $packed);
            if ($remaining <= 0.0001) continue;
            $eligible[] = [
                'qc_inspection_id' => $pass->id,
                'qc_doc_no' => $pass->doc_no,
                'qc_stage' => $pass->stage,
                'qc_verdict' => $pass->verdict,
                'qc_cycle' => $pass->cycle,
                'eligible_qty' => (float)$pass->lot_qty,
                'packed_qty' => $packed,
                'remaining_qty' => $remaining,
                'production_order_id' => $mo->id,
                'production_order_no' => $mo->doc_no,
                'production_order_status' => $mo->status,
                'sales_order_id' => $mo->sales_order_id,
                'sales_order_no' => $mo->salesOrder?->doc_no,
                'style_no' => $mo->style?->style_no,
            ];
        }
        return $eligible;
    }

    public function create(SalesOrder $so, ?int $moId, User $user): PackingList
    {
        return DB::transaction(function () use ($so, $moId, $user) {
            $locked = SalesOrder::withoutGlobalScopes()->whereKey($so->id)->lockForUpdate()->firstOrFail();
            $this->assertAccess($user, (int)$locked->company_id);
            if (!in_array($locked->status, ['CONFIRMED','IN_PROGRESS'], true)) throw new RuntimeException('Packing list hanya untuk SO CONFIRMED/IN_PROGRESS.');
            if ($moId === null) throw new RuntimeException('BR-080: MO wajib dipilih agar source Packing dapat ditelusuri ke QC FINAL.');

            $mo = ProductionOrder::withoutGlobalScopes()->where('company_id', $locked->company_id)
                ->where('sales_order_id', $locked->id)->whereKey($moId)->lockForUpdate()->first();
            if ($mo === null) throw new RuntimeException('MO packing bukan milik SO/company ini.');

            $pass = QcInspection::withoutGlobalScopes()->where('company_id', $locked->company_id)
                ->where('production_order_id', $mo->id)->where('stage', 'FINAL')->where('verdict', 'PASS')
                ->orderByDesc('cycle')->orderByDesc('id')->first();

            $created = PackingList::create([
                'company_id' => $locked->company_id,
                'doc_no' => $this->numbering->next($locked->company_id, 'PL'),
                'sales_order_id' => $locked->id,
                'production_order_id' => $mo->id,
                'qc_inspection_id' => $mo->status === 'QC' ? $pass?->id : null,
                'status' => 'DRAFT',
                'created_by' => $user->id,
            ]);
            $this->audit->record('create', $created, after: [
                'production_order_id' => $mo->id,
                'qc_inspection_id' => $created->qc_inspection_id,
            ]);
            return $created->fresh(['salesOrder.customer', 'productionOrder', 'qcInspection']);
        });
    }

    public function addCarton(PackingList $pl, array $carton, array $lines, User $user): Carton
    {
        if ($lines === []) throw new RuntimeException('Karton wajib punya isi.');

        return DB::transaction(function () use ($pl, $carton, $lines, $user) {
            $locked = PackingList::withoutGlobalScopes()->whereKey($pl->id)->lockForUpdate()->firstOrFail();
            $this->assertAccess($user, (int)$locked->company_id);
            if ($locked->status !== 'DRAFT') throw new RuntimeException('Karton hanya bisa ditambah ke packing list DRAFT.');

            $so = SalesOrder::withoutGlobalScopes()->with('lines', 'customer')
                ->where('company_id', $locked->company_id)->whereKey($locked->sales_order_id)
                ->lockForUpdate()->firstOrFail();
            $mo = $locked->production_order_id ? ProductionOrder::withoutGlobalScopes()
                ->where('company_id', $locked->company_id)->where('sales_order_id', $so->id)
                ->whereKey($locked->production_order_id)->lockForUpdate()->first() : null;

            $seen = [];
            $incomingTotal = 0.0;
            $incomingByMatrix = [];
            foreach ($lines as $line) {
                $qty = (float)($line['qty'] ?? 0);
                $key = ((int)$line['style_id']).'-'.((int)$line['colorway_id']).'-'.((int)$line['size_id']);
                if ($qty <= 0) throw new RuntimeException('Qty carton wajib > 0.');
                if (isset($seen[$key])) throw new RuntimeException('Matrix carton tidak boleh duplikat.');
                $seen[$key] = true;
                if (!DB::table('sales_order_lines')->where('sales_order_id', $locked->sales_order_id)
                    ->where('style_id', $line['style_id'])->where('colorway_id', $line['colorway_id'])
                    ->where('size_id', $line['size_id'])->exists()) throw new RuntimeException('Matrix carton tidak terdapat pada SO.');
                if ($mo !== null && (int)$mo->style_id !== (int)$line['style_id']) throw new RuntimeException('Style carton tidak sesuai MO packing.');
                $incomingTotal += $qty;
                $incomingByMatrix[$key] = ($incomingByMatrix[$key] ?? 0.0) + $qty;
            }

            if ($mo === null) throw new RuntimeException('BR-080: Packing Input wajib memiliki source MO dan QC FINAL PASS.');
            $pass = $this->assertPackingInput($locked, $mo, $user);

            PackingList::withoutGlobalScopes()->where('production_order_id', $mo->id)
                ->where('status', '!=', 'CANCELLED')->lockForUpdate()->get();
            $alreadyPacked = $this->packedQuantityForMo((int)$mo->id);
            if ($alreadyPacked + $incomingTotal - (float)$pass->lot_qty > 0.0001) {
                throw new RuntimeException('BR-080: cumulative carton quantity melebihi quantity QC FINAL PASS yang eligible.');
            }

            $tolerance = (float)($so->tolerance_pct ?? $so->customer?->shipment_tolerance_pct ?? 0);
            foreach ($incomingByMatrix as $key => $incomingQty) {
                [$styleId, $colorwayId, $sizeId] = array_map('intval', explode('-', $key));
                $orderLine = $so->lines->first(fn($line) => (int)$line->style_id === $styleId
                    && (int)$line->colorway_id === $colorwayId && (int)$line->size_id === $sizeId);
                $allocated = $this->packedQuantityForMatrix((int)$mo->id, $styleId, $colorwayId, $sizeId);
                if ($orderLine === null || $allocated + $incomingQty - (float)$orderLine->qty * (1 + $tolerance / 100) > 0.0001) {
                    throw new RuntimeException('BR-021: cumulative carton matrix melebihi SO+toleransi.');
                }
            }

            $gross = $carton['gross_weight_kg'] ?? null;
            $net = $carton['net_weight_kg'] ?? null;
            if (($gross !== null && (float)$gross < 0) || ($net !== null && (float)$net < 0)
                || ($gross !== null && $net !== null && (float)$net > (float)$gross)) throw new RuntimeException('Berat carton tidak valid.');

            $seq = (int)$locked->cartons()->max('seq') + 1;
            $created = $locked->cartons()->create([
                'company_id' => $locked->company_id,
                'carton_no' => $locked->doc_no.'-'.str_pad((string)$seq, 4, '0', STR_PAD_LEFT),
                'seq' => $seq,
                'gross_weight_kg' => $gross,
                'net_weight_kg' => $net,
                'dimension' => $carton['dimension'] ?? null,
            ]);
            foreach ($lines as $line) $created->lines()->create($line);
            $this->audit->record('create', $created, after: [
                'packing_list_id' => $locked->id,
                'qc_inspection_id' => $pass->id,
                'qty' => $incomingTotal,
            ]);
            return $created->load('lines');
        });
    }

    public function finalize(PackingList $pl, int $warehouseId, User $user): PackingList
    {
        return DB::transaction(function () use ($pl, $warehouseId, $user) {
            $locked = PackingList::withoutGlobalScopes()->whereKey($pl->id)->lockForUpdate()->firstOrFail();
            $this->assertAccess($user, (int)$locked->company_id);
            if ($locked->status !== 'DRAFT' || !$locked->cartons()->exists()) throw new RuntimeException('Packing list harus DRAFT dan memiliki karton.');

            $so = SalesOrder::withoutGlobalScopes()->with('lines', 'customer')
                ->where('company_id', $locked->company_id)->whereKey($locked->sales_order_id)
                ->lockForUpdate()->firstOrFail();
            if (!DB::table('warehouses')->where('company_id', $locked->company_id)->where('type', 'FG')->where('id', $warehouseId)->exists()) throw new RuntimeException('Warehouse finalize wajib warehouse FG pada company yang sama.');
            $pcs = Uom::withoutGlobalScopes()->where('company_id', $locked->company_id)->where('code', 'PCS')->first();
            if ($pcs === null) throw new RuntimeException('PCS UOM belum dikonfigurasi pada company ini.');

            if (!$locked->production_order_id) throw new RuntimeException('BR-080: Packing List tanpa source MO/QC tidak dapat difinalisasi.');
            $mo = ProductionOrder::withoutGlobalScopes()->where('company_id', $locked->company_id)
                ->where('sales_order_id', $so->id)->whereKey($locked->production_order_id)
                ->lockForUpdate()->firstOrFail();
            $pass = $this->assertPackingInput($locked, $mo, $user);

            $packed = DB::table('carton_lines')->join('cartons', 'cartons.id', '=', 'carton_lines.carton_id')
                ->where('cartons.packing_list_id', $locked->id)
                ->selectRaw('style_id,colorway_id,size_id,SUM(qty) qty')
                ->groupBy('style_id', 'colorway_id', 'size_id')->get();
            $currentTotal = (float)$packed->sum('qty');
            if ($this->packedQuantityForMo((int)$mo->id) - (float)$pass->lot_qty > 0.0001) {
                throw new RuntimeException('BR-080: packed quantity melebihi source QC FINAL PASS.');
            }

            $tolerance = (float)($so->tolerance_pct ?? $so->customer?->shipment_tolerance_pct ?? 0);
            foreach ($packed as $row) {
                $ordered = (float)$so->lines->first(fn($line) => (int)$line->style_id === (int)$row->style_id
                    && (int)$line->colorway_id === (int)$row->colorway_id && (int)$line->size_id === (int)$row->size_id)?->qty;
                $prior = (float)DB::table('carton_lines')->join('cartons', 'cartons.id', '=', 'carton_lines.carton_id')
                    ->join('packing_lists', 'packing_lists.id', '=', 'cartons.packing_list_id')
                    ->where('packing_lists.sales_order_id', $so->id)->where('packing_lists.status', 'APPROVED')
                    ->where('carton_lines.style_id', $row->style_id)->where('carton_lines.colorway_id', $row->colorway_id)
                    ->where('carton_lines.size_id', $row->size_id)->sum('carton_lines.qty');
                if ($ordered <= 0 || $prior + (float)$row->qty - $ordered * (1 + $tolerance / 100) > 0.0001) throw new RuntimeException('BR-021: cumulative packed quantity melebihi SO+toleransi.');
            }

            $priorMo = (float)DB::table('carton_lines')->join('cartons', 'cartons.id', '=', 'carton_lines.carton_id')
                ->join('packing_lists', 'packing_lists.id', '=', 'cartons.packing_list_id')
                ->where('packing_lists.production_order_id', $mo->id)->where('packing_lists.status', 'APPROVED')
                ->sum('carton_lines.qty');
            if ($priorMo + $currentTotal - (float)$mo->qty_produced > 0.0001) throw new RuntimeException('Packed quantity melebihi qty produced MO.');

            $itsLines = $packed->map(fn($row) => [
                'item_type' => 'FG', 'style_id' => $row->style_id, 'colorway_id' => $row->colorway_id,
                'size_id' => $row->size_id, 'warehouse_id' => $warehouseId,
                'qty' => (float)$row->qty, 'uom_id' => $pcs->id,
            ])->all();
            $this->its->post('PRODUCTION_RECEIPT', [
                'company_id' => $locked->company_id,
                'source_document_type' => 'packing_lists',
                'source_document_id' => $locked->id,
            ], $itsLines, $user);

            $locked->update(['status' => 'APPROVED', 'updated_by' => $user->id]);
            if ($priorMo + $currentTotal + 0.0001 >= (float)$mo->qty_produced) $mo->update(['status' => 'PACKED', 'updated_by' => $user->id]);
            $this->audit->record('update', $locked, after: [
                'status' => 'APPROVED', 'cartons' => $locked->cartons()->count(),
                'qc_inspection_id' => $pass->id,
            ]);
            return $locked->fresh(['cartons.lines', 'qcInspection', 'productionOrder']);
        });
    }

    public function lineage(PackingList $pl, User $user): array
    {
        $loaded = PackingList::withoutGlobalScopes()->with([
            'salesOrder.customer', 'productionOrder', 'qcInspection', 'cartons.lines', 'shipment.lines',
        ])->whereKey($pl->id)->firstOrFail();
        $this->assertAccess($user, (int)$loaded->company_id);
        $receipt = DB::table('stock_movements')->where('company_id', $loaded->company_id)
            ->where('movement_type', 'PRODUCTION_RECEIPT')->where('source_document_type', 'packing_lists')
            ->where('source_document_id', $loaded->id)->first();

        return [
            'packing_list' => ['id'=>$loaded->id, 'doc_no'=>$loaded->doc_no, 'status'=>$loaded->status],
            'sales_order' => $loaded->salesOrder ? ['id'=>$loaded->salesOrder->id, 'doc_no'=>$loaded->salesOrder->doc_no] : null,
            'production_order' => $loaded->productionOrder ? ['id'=>$loaded->productionOrder->id, 'doc_no'=>$loaded->productionOrder->doc_no, 'status'=>$loaded->productionOrder->status] : null,
            'packing_input' => $loaded->qcInspection ? [
                'source_type'=>'QC_FINAL_INSPECTION', 'id'=>$loaded->qcInspection->id,
                'doc_no'=>$loaded->qcInspection->doc_no, 'verdict'=>$loaded->qcInspection->verdict,
                'lot_qty'=>(float)$loaded->qcInspection->lot_qty,
            ] : ['source_type'=>'QC_FINAL_INSPECTION', 'status'=>'MISSING_LEGACY_SOURCE'],
            'cartons' => $loaded->cartons->map(fn($carton) => [
                'id'=>$carton->id, 'carton_no'=>$carton->carton_no,
                'qty'=>(float)$carton->lines->sum('qty'), 'lines'=>$carton->lines,
            ])->values(),
            'carton_allocation' => [
                'matrix_supported'=>true,
                'direct_bundle_or_finishing_output_link'=>false,
                'authority'=>'NOT_DEFINED',
            ],
            'fg_boundary' => [
                'defined_by'=>'PF-09/BR-013',
                'production_receipt_posted'=>$receipt !== null,
                'stock_movement_id'=>$receipt?->id,
                'status'=>$receipt !== null ? 'FG_RECEIVED' : 'PENDING_PACKING_FINALIZE',
            ],
            'shipment_boundary' => $loaded->shipment ? [
                'id'=>$loaded->shipment->id, 'doc_no'=>$loaded->shipment->doc_no,
                'status'=>$loaded->shipment->status,
            ] : ['status'=>'NOT_CREATED', 'automatic_creation'=>false],
        ];
    }

    private function assertPackingInput(PackingList $pl, ProductionOrder $mo, User $user): QcInspection
    {
        if ($mo->status !== 'QC') throw new RuntimeException('BR-080: MO wajib berstatus QC setelah QC FINAL PASS sebelum Packing.');
        $query = QcInspection::withoutGlobalScopes()->where('company_id', $pl->company_id)
            ->where('production_order_id', $mo->id)->where('stage', 'FINAL')->where('verdict', 'PASS');
        $pass = $pl->qc_inspection_id
            ? $query->whereKey($pl->qc_inspection_id)->lockForUpdate()->first()
            : $query->orderByDesc('cycle')->orderByDesc('id')->lockForUpdate()->first();
        if ($pass === null) throw new RuntimeException('BR-080: hanya QC FINAL PASS yang dapat menjadi Packing Input.');
        if (!$pl->qc_inspection_id) {
            $pl->update(['qc_inspection_id' => $pass->id, 'updated_by' => $user->id]);
            $this->audit->record('update', $pl, after: ['qc_inspection_id' => $pass->id]);
        }
        return $pass;
    }

    private function packedQuantityForMo(int $moId): float
    {
        return (float)DB::table('carton_lines')->join('cartons', 'cartons.id', '=', 'carton_lines.carton_id')
            ->join('packing_lists', 'packing_lists.id', '=', 'cartons.packing_list_id')
            ->where('packing_lists.production_order_id', $moId)
            ->where('packing_lists.status', '!=', 'CANCELLED')->sum('carton_lines.qty');
    }

    private function packedQuantityForMatrix(int $moId, int $styleId, int $colorwayId, int $sizeId): float
    {
        return (float)DB::table('carton_lines')->join('cartons', 'cartons.id', '=', 'carton_lines.carton_id')
            ->join('packing_lists', 'packing_lists.id', '=', 'cartons.packing_list_id')
            ->where('packing_lists.production_order_id', $moId)->where('packing_lists.status', '!=', 'CANCELLED')
            ->where('carton_lines.style_id', $styleId)->where('carton_lines.colorway_id', $colorwayId)
            ->where('carton_lines.size_id', $sizeId)->sum('carton_lines.qty');
    }

    private function assertAccess(User $user, int $companyId): void
    {
        if ((int)$user->company_id !== $companyId && !$user->companies()->whereKey($companyId)->exists()) throw new RuntimeException('User tidak memiliki akses ke company packing.');
    }
}
