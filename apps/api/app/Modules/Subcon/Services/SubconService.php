<?php

namespace Modules\Subcon\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Cutting\Models\Bundle;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockLedger;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\MasterData\Models\Material;
use Modules\MasterData\Models\Supplier;
use Modules\MasterData\Models\Uom;
use Modules\MasterData\Models\Warehouse;
use Modules\Production\Models\ProductionOrder;
use Modules\Subcon\Models\SubconFee;
use Modules\Subcon\Models\SubconOrder;
use Modules\Subcon\Models\SubconOrderLine;
use RuntimeException;

class SubconService
{
    public function __construct(
        private NumberingService $numbering,
        private InventoryTransactionService $its,
        private AuditService $audit,
    ) {}

    public function eligibleMaterials(int $companyId, User $user): array
    {
        $this->assertAccess($user, $companyId);

        return StockBalance::withoutGlobalScopes()
            ->with(['material', 'warehouse'])
            ->where('company_id', $companyId)
            ->where('item_type', 'MATERIAL')
            ->where('ownership', 'COMPANY')
            ->whereHas('material', fn ($query) => $query->where('is_active', true))
            ->whereHas('warehouse', fn ($query) => $query->where('is_active', true)->where('type', '!=', 'SUBCON_VIRTUAL'))
            ->orderBy('warehouse_id')
            ->orderBy('material_id')
            ->get()
            ->filter(fn (StockBalance $balance) => $balance->available() > 0.0001)
            ->map(function (StockBalance $balance): ?array {
                $uomId = $balance->material?->use_uom_id;
                if (! $uomId) {
                    $uomId = StockLedger::withoutGlobalScopes()
                        ->where('company_id', $balance->company_id)
                        ->where('item_type', 'MATERIAL')
                        ->where('material_id', $balance->material_id)
                        ->where('warehouse_id', $balance->warehouse_id)
                        ->where('ownership', 'COMPANY')
                        ->latest('id')
                        ->value('uom_id');
                }
                if (! $uomId) {
                    return null;
                }
                $uom = Uom::withoutGlobalScopes()->where('company_id', $balance->company_id)->find($uomId);
                if (! $uom) {
                    return null;
                }

                return [
                    'stock_balance_id' => $balance->id,
                    'material' => $balance->material?->only(['id', 'code', 'name', 'type']),
                    'warehouse' => $balance->warehouse?->only(['id', 'code', 'name', 'type']),
                    'uom' => $uom->only(['id', 'code', 'name']),
                    'available_qty' => round($balance->available(), 4),
                    'location_id' => $balance->location_id,
                    'lot_no' => $balance->lot_no,
                    'roll_id' => $balance->roll_id,
                    'ownership' => 'COMPANY',
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function createAndSend(
        int $companyId,
        ProductionOrder $mo,
        int $supplierId,
        array $lines,
        array $header,
        User $user,
    ): SubconOrder {
        if ($lines === []) {
            throw new RuntimeException('Subcon order wajib punya minimal 1 line.');
        }

        return DB::transaction(function () use ($companyId, $mo, $supplierId, $lines, $header, $user): SubconOrder {
            $this->assertAccess($user, $companyId);
            $lockedMo = ProductionOrder::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereKey($mo->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (in_array($lockedMo->status, ['CLOSED', 'CANCELLED'], true)) {
                throw new RuntimeException('MO tidak dapat dikirim ke subcon.');
            }

            $supplier = Supplier::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereKey($supplierId)
                ->where('is_active', true)
                ->first();
            if (! $supplier || ! $supplier->isSubcon()) {
                throw new RuntimeException('Supplier aktif tidak ditemukan pada company atau bukan type SUBCON.');
            }

            $warehouse = Warehouse::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereKey((int) ($header['warehouse_id'] ?? 0))
                ->where('is_active', true)
                ->where('type', '!=', 'SUBCON_VIRTUAL')
                ->first();
            if (! $warehouse) {
                throw new RuntimeException('Warehouse sumber aktif tidak ditemukan pada company ini.');
            }

            $operationId = ! empty($header['operation_id']) ? (int) $header['operation_id'] : null;
            if ($operationId !== null) {
                $routing = $lockedMo->routingVersion;
                if (! $routing || ! $routing->operations()->where('operation_id', $operationId)->exists()) {
                    throw new RuntimeException('Operation subcon tidak ada di routing snapshot MO.');
                }
            }

            $fee = (float) ($header['fee_per_pcs'] ?? 0);
            if ($fee < 0) {
                throw new RuntimeException('Fee per pcs tidak boleh negatif.');
            }

            $seen = [];
            $normalized = [];
            foreach ($lines as $input) {
                $qty = (float) ($input['qty_sent'] ?? 0);
                $materialId = ! empty($input['material_id']) ? (int) $input['material_id'] : null;
                $bundleId = ! empty($input['bundle_id']) ? (int) $input['bundle_id'] : null;
                $balanceId = ! empty($input['stock_balance_id']) ? (int) $input['stock_balance_id'] : null;
                if ($qty <= 0 || ($materialId === null && $bundleId === null && $balanceId === null) || ($bundleId !== null && ($materialId !== null || $balanceId !== null))) {
                    throw new RuntimeException('Line subcon harus berisi tepat satu sumber material atau bundle dengan qty > 0.');
                }

                $itsLine = null;
                $uomId = ! empty($input['uom_id']) ? (int) $input['uom_id'] : null;
                if ($balanceId !== null || $materialId !== null) {
                    $balance = null;
                    if ($balanceId !== null) {
                        $balance = StockBalance::withoutGlobalScopes()
                            ->where('company_id', $companyId)
                            ->where('item_type', 'MATERIAL')
                            ->where('ownership', 'COMPANY')
                            ->whereKey($balanceId)
                            ->lockForUpdate()
                            ->first();
                        if (! $balance || (int) $balance->warehouse_id !== (int) $warehouse->id) {
                            throw new RuntimeException('Eligible material balance tidak cocok dengan warehouse sumber.');
                        }
                        if ($materialId !== null && (int) $balance->material_id !== $materialId) {
                            throw new RuntimeException('Material tidak cocok dengan eligible stock balance.');
                        }
                        if ($qty - $balance->available() > 0.0001) {
                            throw new RuntimeException("Qty kirim {$qty} melebihi eligible stock {$balance->available()}.");
                        }
                        $materialId = (int) $balance->material_id;
                    }

                    $material = Material::withoutGlobalScopes()
                        ->where('company_id', $companyId)
                        ->whereKey($materialId)
                        ->where('is_active', true)
                        ->first();
                    if (! $material) {
                        throw new RuntimeException('Material aktif tidak ditemukan pada company ini.');
                    }
                    $uomId = $uomId ?: ($material->use_uom_id ? (int) $material->use_uom_id : null);
                    if (! $uomId || ! Uom::withoutGlobalScopes()->where('company_id', $companyId)->whereKey($uomId)->exists()) {
                        throw new RuntimeException('UOM material subcon tidak valid.');
                    }
                    if ($material->use_uom_id && (int) $material->use_uom_id !== $uomId) {
                        throw new RuntimeException('UOM kirim harus menggunakan use-UOM material.');
                    }

                    $key = $balanceId ? 'S'.$balanceId : 'M'.$materialId;
                    if (isset($seen[$key])) {
                        throw new RuntimeException('Line subcon duplikat.');
                    }
                    $seen[$key] = true;
                    $itsLine = [
                        'item_type' => 'MATERIAL',
                        'material_id' => $materialId,
                        'warehouse_id' => $warehouse->id,
                        'location_id' => $balance?->location_id,
                        'lot_no' => $balance?->lot_no,
                        'roll_id' => $balance?->roll_id,
                        'ownership' => 'COMPANY',
                        'qty' => $qty,
                        'uom_id' => $uomId,
                    ];
                } else {
                    $bundle = Bundle::withoutGlobalScopes()
                        ->where('company_id', $companyId)
                        ->where('production_order_id', $lockedMo->id)
                        ->whereKey($bundleId)
                        ->first();
                    if (! $bundle) {
                        throw new RuntimeException('Bundle subcon bukan milik MO/company ini.');
                    }
                    $key = 'B'.$bundleId;
                    if (isset($seen[$key])) {
                        throw new RuntimeException('Line subcon duplikat.');
                    }
                    $seen[$key] = true;
                }

                $normalized[] = compact('materialId', 'bundleId', 'qty', 'uomId', 'itsLine');
            }

            $clientReference = trim((string) ($header['client_reference'] ?? '')) ?: null;
            $values = [
                'doc_no' => $this->numbering->next($companyId, 'JW'),
                'supplier_id' => $supplier->id,
                'production_order_id' => $lockedMo->id,
                'operation_id' => $operationId,
                'sent_date' => now()->toDateString(),
                'expected_return' => $header['expected_return'] ?? null,
                'fee_per_pcs' => $fee,
                'status' => 'DRAFT',
                'created_by' => $user->id,
            ];

            if ($clientReference) {
                $order = SubconOrder::withoutGlobalScopes()->firstOrCreate(
                    ['company_id' => $companyId, 'client_reference' => $clientReference],
                    $values,
                );
                if (! $order->wasRecentlyCreated) {
                    if ((int) $order->production_order_id !== (int) $lockedMo->id || (int) $order->supplier_id !== (int) $supplier->id) {
                        throw new RuntimeException('Client reference sudah dipakai untuk subcon order berbeda.');
                    }
                    return $order->load('lines.material', 'lines.bundle', 'lines.uom', 'supplier', 'productionOrder', 'operation');
                }
            } else {
                $order = SubconOrder::create(array_merge(['company_id' => $companyId], $values));
            }

            $itsLines = [];
            foreach ($normalized as $row) {
                $line = $order->lines()->create([
                    'material_id' => $row['materialId'],
                    'bundle_id' => $row['bundleId'],
                    'qty_sent' => $row['qty'],
                    'qty_returned' => 0,
                    'uom_id' => $row['uomId'],
                ]);
                if ($row['itsLine']) {
                    $itsLines[] = array_merge($row['itsLine'], ['source_document_line_id' => $line->id]);
                }
            }

            if ($itsLines !== []) {
                $this->its->post('SUBCON_OUT', [
                    'company_id' => $companyId,
                    'source_document_type' => 'subcon_orders',
                    'source_document_id' => $order->id,
                ], $itsLines, $user);
            }

            $order->update(['status' => 'SENT', 'updated_by' => $user->id]);
            $this->audit->record('create', $order, after: [
                'doc_no' => $order->doc_no,
                'supplier' => $supplier->code,
                'material_lines' => count($itsLines),
                'bundle_movement_authority' => 'NOT_DEFINED',
            ]);

            return $order->load('lines.material', 'lines.bundle', 'lines.uom', 'supplier', 'productionOrder', 'operation');
        });
    }

    public function receive(SubconOrder $order, array $returns, User $user): SubconOrder
    {
        if ($returns === []) {
            throw new RuntimeException('Return subcon wajib punya minimal 1 line.');
        }

        return DB::transaction(function () use ($order, $returns, $user): SubconOrder {
            $locked = SubconOrder::withoutGlobalScopes()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertAccess($user, (int) $locked->company_id);
            if (! in_array($locked->status, ['SENT', 'PARTIAL_RETURNED'], true)) {
                throw new RuntimeException("Subcon order {$locked->status} tidak bisa menerima return.");
            }

            $seen = [];
            $processed = 0;
            foreach ($returns as $input) {
                $lineId = (int) ($input['line_id'] ?? 0);
                if (isset($seen[$lineId])) {
                    throw new RuntimeException('Return line subcon duplikat dalam request.');
                }
                $seen[$lineId] = true;

                $line = $locked->lines()->whereKey($lineId)->lockForUpdate()->first();
                if (! $line) {
                    throw new RuntimeException('Return line bukan milik subcon order ini.');
                }

                $qty = (float) ($input['qty_returned'] ?? 0);
                $warehouseId = (int) ($input['warehouse_id'] ?? 0);
                $receiptReference = trim((string) ($input['receipt_reference'] ?? '')) ?: null;
                $warehouse = Warehouse::withoutGlobalScopes()
                    ->where('company_id', $locked->company_id)
                    ->whereKey($warehouseId)
                    ->where('is_active', true)
                    ->where('type', '!=', 'SUBCON_VIRTUAL')
                    ->first();
                if (! $warehouse) {
                    throw new RuntimeException('Warehouse return aktif tidak ditemukan pada company ini.');
                }

                if ($receiptReference) {
                    $existing = $locked->fees()->where('receipt_reference', $receiptReference)->first();
                    if ($existing) {
                        if ((int) $existing->subcon_order_line_id !== $lineId || abs((float) $existing->qty_returned - $qty) > 0.0001 || (int) $existing->warehouse_id !== $warehouseId) {
                            throw new RuntimeException('Receipt reference sudah dipakai dengan payload berbeda.');
                        }
                        continue;
                    }
                }

                $remaining = (float) $line->qty_sent - (float) $line->qty_returned;
                if ($qty <= 0 || $qty - $remaining > 0.0001) {
                    throw new RuntimeException("Return {$qty} melebihi sisa kirim {$remaining}.");
                }

                $sourceLedger = null;
                if ($line->material_id) {
                    $sourceRows = StockLedger::withoutGlobalScopes()
                        ->where('company_id', $locked->company_id)
                        ->where('movement_type', 'SUBCON_OUT')
                        ->where('source_document_type', 'subcon_orders')
                        ->where('source_document_id', $locked->id)
                        ->where('source_document_line_id', $line->id)
                        ->get();
                    if ($sourceRows->count() !== 1) {
                        throw new RuntimeException('Source SUBCON_OUT line tidak tunggal atau tidak ditemukan.');
                    }
                    $sourceLedger = $sourceRows->first();
                    if ($sourceLedger->ownership !== 'COMPANY') {
                        throw new RuntimeException('BR-090 hanya mendukung material milik COMPANY pada flow existing.');
                    }
                    if ((int) $sourceLedger->warehouse_id !== $warehouseId) {
                        throw new RuntimeException('Warehouse return harus sama dengan source warehouse SUBCON_OUT pada ITS existing.');
                    }
                }

                $fee = $locked->fees()->create([
                    'subcon_order_line_id' => $line->id,
                    'warehouse_id' => $warehouseId,
                    'receipt_reference' => $receiptReference,
                    'return_date' => now()->toDateString(),
                    'qty_returned' => $qty,
                    'fee_per_pcs' => (float) $locked->fee_per_pcs,
                    'total_fee' => round($qty * (float) $locked->fee_per_pcs, 4),
                ]);

                if ($sourceLedger) {
                    $this->its->post('SUBCON_IN', [
                        'company_id' => $locked->company_id,
                        'source_document_type' => 'subcon_fees',
                        'source_document_id' => $fee->id,
                    ], [[
                        'item_type' => $sourceLedger->item_type,
                        'material_id' => $sourceLedger->material_id,
                        'style_id' => $sourceLedger->style_id,
                        'colorway_id' => $sourceLedger->colorway_id,
                        'size_id' => $sourceLedger->size_id,
                        'warehouse_id' => $sourceLedger->warehouse_id,
                        'location_id' => $sourceLedger->location_id,
                        'lot_no' => $sourceLedger->lot_no,
                        'roll_id' => $sourceLedger->roll_id,
                        'ownership' => $sourceLedger->ownership,
                        'qty' => $qty,
                        'uom_id' => $sourceLedger->uom_id,
                        'source_document_line_id' => $line->id,
                    ]], $user);
                }

                $line->increment('qty_returned', $qty);
                $processed++;
            }

            $full = $locked->lines()->whereColumn('qty_returned', '<', 'qty_sent')->doesntExist();
            $newStatus = $full ? 'RETURNED' : 'PARTIAL_RETURNED';
            if ($locked->status !== $newStatus) {
                $locked->update(['status' => $newStatus, 'updated_by' => $user->id]);
            }
            if ($processed > 0) {
                $this->audit->record('update', $locked, after: [
                    'status' => $newStatus,
                    'receipts' => $processed,
                    'qc_wip_handoff_authority' => 'NOT_DEFINED',
                ]);
            }

            return $locked->fresh([
                'lines.material', 'lines.bundle', 'lines.uom',
                'fees.line', 'fees.warehouse', 'supplier', 'productionOrder', 'operation',
            ]);
        });
    }

    public function lineage(SubconOrder $order, User $user): array
    {
        $order = SubconOrder::withoutGlobalScopes()
            ->with([
                'supplier', 'productionOrder.style', 'operation',
                'lines.material', 'lines.bundle', 'lines.uom',
                'fees.line', 'fees.warehouse',
            ])
            ->whereKey($order->id)
            ->firstOrFail();
        $this->assertAccess($user, (int) $order->company_id);

        $outboundMovement = StockMovement::withoutGlobalScopes()
            ->where('company_id', $order->company_id)
            ->where('movement_type', 'SUBCON_OUT')
            ->where('source_document_type', 'subcon_orders')
            ->where('source_document_id', $order->id)
            ->first();
        $outboundLedger = StockLedger::withoutGlobalScopes()
            ->where('company_id', $order->company_id)
            ->where('movement_type', 'SUBCON_OUT')
            ->where('source_document_type', 'subcon_orders')
            ->where('source_document_id', $order->id)
            ->get();

        $receipts = $order->fees->map(function (SubconFee $fee) use ($order): array {
            $movement = StockMovement::withoutGlobalScopes()
                ->where('company_id', $order->company_id)
                ->where('movement_type', 'SUBCON_IN')
                ->where('source_document_type', 'subcon_fees')
                ->where('source_document_id', $fee->id)
                ->first();
            $ledger = StockLedger::withoutGlobalScopes()
                ->where('company_id', $order->company_id)
                ->where('movement_type', 'SUBCON_IN')
                ->where('source_document_type', 'subcon_fees')
                ->where('source_document_id', $fee->id)
                ->first();

            return [
                'id' => $fee->id,
                'receipt_reference' => $fee->receipt_reference,
                'line_id' => $fee->subcon_order_line_id ?: $ledger?->source_document_line_id,
                'warehouse' => $fee->warehouse?->only(['id', 'code', 'name', 'type']),
                'return_date' => $fee->return_date?->toDateString(),
                'qty_returned' => (float) $fee->qty_returned,
                'fee_per_pcs' => (float) $fee->fee_per_pcs,
                'total_fee' => (float) $fee->total_fee,
                'movement' => $movement?->only(['id', 'doc_no', 'movement_type']),
                'ledger' => $ledger?->only([
                    'id', 'material_id', 'warehouse_id', 'location_id', 'lot_no', 'roll_id',
                    'ownership', 'qty_in', 'uom_id', 'source_document_line_id',
                ]),
            ];
        })->values();

        return [
            'subcon_order' => [
                'id' => $order->id,
                'doc_no' => $order->doc_no,
                'status' => $order->status,
                'sent_date' => $order->sent_date?->toDateString(),
                'expected_return' => $order->expected_return?->toDateString(),
                'outstanding_days' => $order->status === 'RETURNED' ? 0 : max(0, $order->sent_date?->diffInDays(now()) ?? 0),
                'supplier' => $order->supplier?->only(['id', 'code', 'name', 'type', 'is_active']),
                'production_order' => $order->productionOrder?->only(['id', 'doc_no', 'status', 'style_id']),
                'style' => $order->productionOrder?->style?->only(['id', 'style_no']),
                'operation' => $order->operation?->only(['id', 'code', 'name']),
                'fee_per_pcs' => (float) $order->fee_per_pcs,
            ],
            'lines' => $order->lines->map(function (SubconOrderLine $line) use ($outboundLedger): array {
                $outbound = $outboundLedger->firstWhere('source_document_line_id', $line->id);
                return [
                    'id' => $line->id,
                    'material' => $line->material?->only(['id', 'code', 'name', 'type']),
                    'bundle' => $line->bundle?->only(['id', 'bundle_no', 'qty', 'current_stage', 'status']),
                    'uom' => $line->uom?->only(['id', 'code', 'name']),
                    'qty_sent' => (float) $line->qty_sent,
                    'qty_returned' => (float) $line->qty_returned,
                    'outstanding_qty' => round((float) $line->qty_sent - (float) $line->qty_returned, 4),
                    'outbound_ledger' => $outbound?->only([
                        'id', 'material_id', 'warehouse_id', 'location_id', 'lot_no', 'roll_id',
                        'ownership', 'qty_out', 'uom_id', 'source_document_line_id',
                    ]),
                    'bundle_movement_authority' => $line->bundle_id ? 'NOT_DEFINED' : null,
                ];
            })->values(),
            'outbound_movement' => $outboundMovement?->only(['id', 'doc_no', 'movement_type']),
            'receipts' => $receipts,
            'authorities' => [
                'subcontract_document' => 'JOB_WORK_ORDER_EXISTING',
                'material_ownership' => 'COMPANY — BR-090',
                'inventory_movement' => 'ITS SUBCON_OUT/SUBCON_IN',
                'vendor_processing_detail' => 'NOT_DEFINED',
                'bundle_wip_movement' => 'NOT_DEFINED',
                'loss_yield_scrap' => 'NOT_DEFINED',
                'qc_wip_handoff' => 'NOT_DEFINED',
                'fg_handoff' => 'NOT_DEFINED',
                'service_cost' => 'subcon_fees → ActualCostingService — BR-091',
                'vendor_invoice_ap_match' => 'NOT_IMPLEMENTED',
            ],
        ];
    }

    private function assertAccess(User $user, int $companyId): void
    {
        if ((int) $user->company_id !== $companyId && ! $user->companies()->whereKey($companyId)->exists()) {
            throw new RuntimeException('User tidak memiliki akses ke company subcon.');
        }
    }
}
