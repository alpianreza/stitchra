<?php

namespace Modules\Shipping\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\MasterData\Models\Uom;
use Modules\Packing\Models\PackingList;
use Modules\Sales\Models\SalesOrder;
use Modules\Shipping\Models\Shipment;
use RuntimeException;

class ShipmentService
{
    public function __construct(
        private NumberingService $numbering,
        private InventoryTransactionService $its,
        private AuditService $audit,
    ) {}

    public function eligibleFg(int $companyId, User $user): array
    {
        $this->assertAccess($user, $companyId);
        $packingLists = PackingList::withoutGlobalScopes()
            ->with(['salesOrder.customer', 'productionOrder', 'qcInspection', 'cartons.lines'])
            ->where('company_id', $companyId)
            ->where('status', 'APPROVED')
            ->whereDoesntHave('shipment')
            ->orderByDesc('id')->get();

        $eligible = [];
        foreach ($packingLists as $packingList) {
            try {
                $receipt = $this->assertEligibleSource($packingList);
            } catch (RuntimeException) {
                continue;
            }
            $eligible[] = [
                'packing_list_id' => $packingList->id,
                'packing_list_no' => $packingList->doc_no,
                'sales_order_id' => $packingList->sales_order_id,
                'sales_order_no' => $packingList->salesOrder?->doc_no,
                'buyer' => $packingList->salesOrder?->customer?->name,
                'production_order_id' => $packingList->production_order_id,
                'production_order_no' => $packingList->productionOrder?->doc_no,
                'qc_inspection_id' => $packingList->qc_inspection_id,
                'qc_doc_no' => $packingList->qcInspection?->doc_no,
                'warehouse_id' => $receipt['warehouse']['id'],
                'warehouse_code' => $receipt['warehouse']['code'],
                'production_receipt_id' => $receipt['movement']->id,
                'production_receipt_no' => $receipt['movement']->doc_no,
                'received_qty' => $receipt['received_qty'],
                'available_qty' => $receipt['available_qty'],
                'lines' => $receipt['lines'],
            ];
        }
        return $eligible;
    }

    public function create(PackingList $pl, array $header, User $user): Shipment
    {
        return DB::transaction(function () use ($pl, $header, $user) {
            $locked = PackingList::withoutGlobalScopes()->whereKey($pl->id)->lockForUpdate()->firstOrFail();
            $this->assertAccess($user, (int) $locked->company_id);
            if ($locked->status !== 'APPROVED') throw new RuntimeException('Shipment hanya dari packing list APPROVED.');
            if (Shipment::withoutGlobalScopes()->where('packing_list_id', $locked->id)->exists()) throw new RuntimeException('Packing list sudah memiliki shipment.');
            if (empty($header['ship_date'])) throw new RuntimeException('Ship date wajib diisi.');
            $receipt = $this->assertEligibleSource($locked);

            $shipment = Shipment::create([
                'company_id' => $locked->company_id,
                'doc_no' => $this->numbering->next($locked->company_id, 'SHP'),
                'sales_order_id' => $locked->sales_order_id,
                'packing_list_id' => $locked->id,
                'ship_date' => $header['ship_date'],
                'forwarder' => $header['forwarder'] ?? null,
                'booking_no' => $header['booking_no'] ?? null,
                'container_no' => $header['container_no'] ?? null,
                'vessel_flight' => $header['vessel_flight'] ?? null,
                'port_of_loading' => $header['port_of_loading'] ?? null,
                'port_of_discharge' => $header['port_of_discharge'] ?? null,
                'status' => 'DRAFT',
                'tolerance_check' => 'PENDING',
                'created_by' => $user->id,
            ]);

            foreach ($this->cartonMatrix((int) $locked->id) as $row) {
                $shipment->lines()->create([
                    'style_id' => $row->style_id,
                    'colorway_id' => $row->colorway_id,
                    'size_id' => $row->size_id,
                    'qty_shipped' => (float) $row->qty,
                ]);
            }
            $this->checkToleranceLocked($shipment->fresh('lines'));
            $this->audit->record('create', $shipment, after: [
                'packing_list' => $locked->doc_no,
                'production_receipt_id' => $receipt['movement']->id,
                'fg_warehouse_id' => $receipt['warehouse']['id'],
            ]);
            return $shipment->fresh(['lines', 'packingList']);
        });
    }

    public function checkTolerance(Shipment $shipment): void
    {
        DB::transaction(function () use ($shipment) {
            $locked = Shipment::withoutGlobalScopes()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $this->checkToleranceLocked($locked->load('lines'));
        });
    }

    private function checkToleranceLocked(Shipment $shipment): void
    {
        $so = SalesOrder::withoutGlobalScopes()->with('lines', 'customer')
            ->where('company_id', $shipment->company_id)->whereKey($shipment->sales_order_id)
            ->lockForUpdate()->firstOrFail();
        $tol = (float) ($so->tolerance_pct ?? $so->customer?->shipment_tolerance_pct ?? 0);
        $result = 'OK';
        foreach ($so->lines as $line) {
            $current = (float) $shipment->lines->first(fn ($x) =>
                (int) $x->style_id === (int) $line->style_id &&
                (int) $x->colorway_id === (int) $line->colorway_id &&
                (int) $x->size_id === (int) $line->size_id
            )?->qty_shipped;
            $prior = (float) DB::table('shipment_lines')->join('shipments', 'shipments.id', '=', 'shipment_lines.shipment_id')
                ->where('shipments.sales_order_id', $so->id)->where('shipments.status', 'SHIPPED')
                ->where('shipment_lines.style_id', $line->style_id)
                ->where('shipment_lines.colorway_id', $line->colorway_id)
                ->where('shipment_lines.size_id', $line->size_id)->sum('shipment_lines.qty_shipped');
            $ordered = (float) $line->qty;
            $projected = $prior + $current;
            if ($projected > $ordered * (1 + $tol / 100) + 0.0001) $result = 'OVER';
            elseif ($projected < $ordered * (1 - $tol / 100) - 0.0001 && $result !== 'OVER') $result = 'UNDER';
        }
        $shipment->update(['tolerance_check' => $result]);
    }

    public function approveOverTolerance(Shipment $shipment, User $user): Shipment
    {
        return DB::transaction(function () use ($shipment, $user) {
            $locked = Shipment::withoutGlobalScopes()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $this->assertAccess($user, (int) $locked->company_id);
            if (!in_array($locked->status, ['DRAFT', 'READY'], true)) throw new RuntimeException('Hanya shipment belum dikirim yang dapat di-approve.');
            if ($locked->tolerance_check === 'OK') throw new RuntimeException('Shipment dalam toleransi tidak butuh override.');
            $locked->update(['over_tolerance_approved' => true, 'updated_by' => $user->id]);
            $this->audit->record('update', $locked, after: ['over_tolerance_approved' => true, 'tolerance_check' => $locked->tolerance_check]);
            return $locked->fresh();
        });
    }

    public function ship(Shipment $shipment, int $warehouseId, User $user): Shipment
    {
        return DB::transaction(function () use ($shipment, $warehouseId, $user) {
            $locked = Shipment::withoutGlobalScopes()->with('lines')->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $this->assertAccess($user, (int) $locked->company_id);
            if (!in_array($locked->status, ['DRAFT', 'READY'], true)) throw new RuntimeException("Shipment {$locked->status} tidak bisa dikirim.");
            if ($locked->tolerance_check !== 'OK' && !$locked->over_tolerance_approved) throw new RuntimeException('BR-021: shipment di luar toleransi buyer — approval wajib.');

            $pl = PackingList::withoutGlobalScopes()->where('company_id', $locked->company_id)
                ->whereKey($locked->packing_list_id)->lockForUpdate()->firstOrFail();
            if ($pl->status !== 'APPROVED') throw new RuntimeException('Packing list shipment tidak lagi APPROVED.');
            $receipt = $this->assertEligibleSource($pl);
            if ((int) $receipt['warehouse']['id'] !== $warehouseId) throw new RuntimeException('Shipment wajib memakai warehouse FG sumber PRODUCTION_RECEIPT Packing List.');
            if (!DB::table('warehouses')->where('company_id', $locked->company_id)->where('type', 'FG')
                ->where('is_active', true)->where('id', $warehouseId)->exists()) throw new RuntimeException('Warehouse shipment wajib FG aktif pada company yang sama.');

            $shipmentMatrix = $locked->lines->map(fn ($line) => (object) [
                'style_id' => $line->style_id, 'colorway_id' => $line->colorway_id,
                'size_id' => $line->size_id, 'qty' => $line->qty_shipped,
            ]);
            $this->assertSameMatrix($this->receiptMatrix($receipt['lines']), $this->matrix($shipmentMatrix), 'Shipment quantity harus sama dengan eligible FG receipt Packing List.');
            foreach ($locked->lines as $line) {
                if ($this->availableQty((int) $locked->company_id, $warehouseId, (int) $line->style_id, (int) $line->colorway_id, (int) $line->size_id) + 0.0001 < (float) $line->qty_shipped) {
                    throw new RuntimeException('FG stock tidak cukup untuk shipment matrix.');
                }
            }

            $pcs = Uom::withoutGlobalScopes()->where('company_id', $locked->company_id)->where('code', 'PCS')->first();
            if ($pcs === null) throw new RuntimeException('PCS UOM belum dikonfigurasi.');
            $lines = $locked->lines->map(fn ($line) => [
                'item_type' => 'FG', 'style_id' => $line->style_id,
                'colorway_id' => $line->colorway_id, 'size_id' => $line->size_id,
                'warehouse_id' => $warehouseId, 'qty' => (float) $line->qty_shipped,
                'uom_id' => $pcs->id, 'source_document_line_id' => $line->id,
            ])->all();
            $movement = $this->its->post('SHIPMENT', [
                'company_id' => $locked->company_id,
                'source_document_type' => 'shipments',
                'source_document_id' => $locked->id,
            ], $lines, $user);

            $locked->update(['status' => 'SHIPPED', 'updated_by' => $user->id]);
            $pl->update(['status' => 'SHIPPED', 'updated_by' => $user->id]);
            $so = SalesOrder::withoutGlobalScopes()->with('lines', 'customer')
                ->where('company_id', $locked->company_id)->whereKey($locked->sales_order_id)
                ->lockForUpdate()->firstOrFail();
            $tol = (float) ($so->tolerance_pct ?? $so->customer?->shipment_tolerance_pct ?? 0);
            $complete = $so->lines->every(function ($line) use ($so, $tol) {
                $qty = (float) DB::table('shipment_lines')->join('shipments', 'shipments.id', '=', 'shipment_lines.shipment_id')
                    ->where('shipments.sales_order_id', $so->id)->where('shipments.status', 'SHIPPED')
                    ->where('shipment_lines.style_id', $line->style_id)
                    ->where('shipment_lines.colorway_id', $line->colorway_id)
                    ->where('shipment_lines.size_id', $line->size_id)->sum('shipment_lines.qty_shipped');
                return $qty + 0.0001 >= (float) $line->qty * (1 - $tol / 100);
            });
            $so->update(['status' => $complete ? 'CLOSED' : 'IN_PROGRESS', 'updated_by' => $user->id]);
            $this->audit->record('update', $locked, after: [
                'status' => 'SHIPPED', 'so' => $so->doc_no,
                'production_receipt_id' => $receipt['movement']->id,
                'shipment_movement_id' => $movement->id,
                'fg_warehouse_id' => $warehouseId,
            ]);
            return $locked->fresh(['lines', 'packingList']);
        });
    }

    public function lineage(Shipment $shipment, User $user): array
    {
        $loaded = Shipment::withoutGlobalScopes()->with([
            'lines', 'salesOrder.customer', 'packingList.productionOrder',
            'packingList.qcInspection', 'packingList.cartons.lines',
        ])->whereKey($shipment->id)->firstOrFail();
        $this->assertAccess($user, (int) $loaded->company_id);
        $receipt = $this->assertEligibleSource($loaded->packingList);
        $shipmentMovement = DB::table('stock_movements')->where('company_id', $loaded->company_id)
            ->where('movement_type', 'SHIPMENT')->where('source_document_type', 'shipments')
            ->where('source_document_id', $loaded->id)->first();

        return [
            'shipment' => ['id' => $loaded->id, 'doc_no' => $loaded->doc_no, 'status' => $loaded->status],
            'sales_order' => ['id' => $loaded->salesOrder?->id, 'doc_no' => $loaded->salesOrder?->doc_no, 'buyer' => $loaded->salesOrder?->customer?->name],
            'production_order' => ['id' => $loaded->packingList?->productionOrder?->id, 'doc_no' => $loaded->packingList?->productionOrder?->doc_no],
            'qc_final' => ['id' => $loaded->packingList?->qcInspection?->id, 'doc_no' => $loaded->packingList?->qcInspection?->doc_no, 'verdict' => $loaded->packingList?->qcInspection?->verdict],
            'packing_list' => ['id' => $loaded->packingList?->id, 'doc_no' => $loaded->packingList?->doc_no, 'status' => $loaded->packingList?->status],
            'cartons' => $loaded->packingList?->cartons->map(fn ($carton) => ['id' => $carton->id, 'carton_no' => $carton->carton_no, 'qty' => (float) $carton->lines->sum('qty')])->values(),
            'production_receipt' => ['id' => $receipt['movement']->id, 'doc_no' => $receipt['movement']->doc_no, 'warehouse' => $receipt['warehouse'], 'received_qty' => $receipt['received_qty']],
            'fg_stock' => ['available_qty' => $receipt['available_qty'], 'lines' => $receipt['lines']],
            'shipment_movement' => $shipmentMovement ? ['id' => $shipmentMovement->id, 'doc_no' => $shipmentMovement->doc_no, 'movement_type' => 'SHIPMENT'] : ['status' => 'NOT_POSTED'],
            'source_authority' => 'PACKING_LIST_CARTONS_VIA_PF_09',
            'automatic_creation' => false,
        ];
    }

    private function assertEligibleSource(PackingList $pl): array
    {
        $loaded = PackingList::withoutGlobalScopes()->with(['qcInspection', 'productionOrder'])
            ->where('company_id', $pl->company_id)->whereKey($pl->id)->firstOrFail();
        if ($loaded->status !== 'APPROVED' && $loaded->status !== 'SHIPPED') throw new RuntimeException('Packing List belum eligible sebagai sumber FG/Shipment.');
        if ($loaded->qcInspection === null || $loaded->qcInspection->stage !== 'FINAL' || $loaded->qcInspection->verdict !== 'PASS'
            || (int) $loaded->qcInspection->company_id !== (int) $loaded->company_id
            || (int) $loaded->qcInspection->production_order_id !== (int) $loaded->production_order_id) {
            throw new RuntimeException('BR-080: source QC FINAL PASS Packing List tidak valid.');
        }

        $movement = DB::table('stock_movements')->where('company_id', $loaded->company_id)
            ->where('movement_type', 'PRODUCTION_RECEIPT')->where('source_document_type', 'packing_lists')
            ->where('source_document_id', $loaded->id)->first();
        if ($movement === null) throw new RuntimeException('Packing List belum memiliki PRODUCTION_RECEIPT yang traceable.');

        $lines = DB::table('stock_ledger')->where('company_id', $loaded->company_id)
            ->where('movement_type', 'PRODUCTION_RECEIPT')->where('source_document_type', 'packing_lists')
            ->where('source_document_id', $loaded->id)
            ->selectRaw('warehouse_id, style_id, colorway_id, size_id, uom_id, SUM(qty_in) received_qty')
            ->groupBy('warehouse_id', 'style_id', 'colorway_id', 'size_id', 'uom_id')->get();
        if ($lines->isEmpty()) throw new RuntimeException('PRODUCTION_RECEIPT Packing List tidak memiliki FG ledger lines.');
        $warehouseIds = $lines->pluck('warehouse_id')->unique()->values();
        if ($warehouseIds->count() !== 1) throw new RuntimeException('PRODUCTION_RECEIPT Packing List harus menuju satu warehouse FG.');
        $warehouse = DB::table('warehouses')->where('company_id', $loaded->company_id)
            ->where('type', 'FG')->where('is_active', true)->where('id', $warehouseIds->first())->first();
        if ($warehouse === null) throw new RuntimeException('Warehouse sumber PRODUCTION_RECEIPT bukan FG aktif pada company Packing List.');

        $carton = $this->matrix($this->cartonMatrix((int) $loaded->id));
        $receipt = $this->receiptMatrix($lines);
        $this->assertSameMatrix($carton, $receipt, 'FG receipt quantity harus sama dengan Carton/Packing quantity.');
        $lineData = $lines->map(function ($line) use ($loaded) {
            $available = $this->availableQty((int) $loaded->company_id, (int) $line->warehouse_id, (int) $line->style_id, (int) $line->colorway_id, (int) $line->size_id);
            return [
                'style_id' => (int) $line->style_id,
                'colorway_id' => (int) $line->colorway_id,
                'size_id' => (int) $line->size_id,
                'uom_id' => (int) $line->uom_id,
                'received_qty' => (float) $line->received_qty,
                'available_qty' => $available,
            ];
        })->values()->all();
        return [
            'movement' => $movement,
            'warehouse' => ['id' => (int) $warehouse->id, 'code' => $warehouse->code, 'name' => $warehouse->name],
            'lines' => $lineData,
            'received_qty' => array_sum(array_column($lineData, 'received_qty')),
            'available_qty' => array_sum(array_column($lineData, 'available_qty')),
        ];
    }

    private function cartonMatrix(int $packingListId): Collection
    {
        $rows = DB::table('carton_lines')->join('cartons', 'cartons.id', '=', 'carton_lines.carton_id')
            ->where('cartons.packing_list_id', $packingListId)
            ->selectRaw('style_id, colorway_id, size_id, SUM(qty) qty')
            ->groupBy('style_id', 'colorway_id', 'size_id')->get();
        if ($rows->isEmpty()) throw new RuntimeException('Packing List tidak memiliki Carton lines.');
        return $rows;
    }

    private function matrix(iterable $rows): array
    {
        $matrix = [];
        foreach ($rows as $row) $matrix[$this->matrixKey((int) $row->style_id, (int) $row->colorway_id, (int) $row->size_id)] = (float) $row->qty;
        ksort($matrix);
        return $matrix;
    }

    private function receiptMatrix(array $lines): array
    {
        $matrix = [];
        foreach ($lines as $line) $matrix[$this->matrixKey((int) $line['style_id'], (int) $line['colorway_id'], (int) $line['size_id'])] = (float) $line['received_qty'];
        ksort($matrix);
        return $matrix;
    }

    private function assertSameMatrix(array $expected, array $actual, string $message): void
    {
        if (array_keys($expected) !== array_keys($actual)) throw new RuntimeException($message);
        foreach ($expected as $key => $qty) if (abs($qty - $actual[$key]) > 0.0001) throw new RuntimeException($message);
    }

    private function matrixKey(int $styleId, int $colorwayId, int $sizeId): string
    {
        return $styleId.'-'.$colorwayId.'-'.$sizeId;
    }

    private function availableQty(int $companyId, int $warehouseId, int $styleId, int $colorwayId, int $sizeId): float
    {
        return (float) DB::table('stock_balances')->where('company_id', $companyId)
            ->where('item_type', 'FG')->where('warehouse_id', $warehouseId)
            ->where('style_id', $styleId)->where('colorway_id', $colorwayId)->where('size_id', $sizeId)
            ->selectRaw('COALESCE(SUM(on_hand - reserved - quality_hold), 0) available_qty')->value('available_qty');
    }

    private function assertAccess(User $user, int $companyId): void
    {
        if ((int) $user->company_id !== $companyId && !$user->companies()->whereKey($companyId)->exists()) throw new RuntimeException('User tidak memiliki akses ke company shipment.');
    }
}
