<?php

use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\MasterData\Models\Material;
use Modules\MasterData\Models\Supplier;
use Modules\MasterData\Models\Uom;
use Modules\MasterData\Models\Warehouse;
use Modules\Subcon\Services\SubconService;

function subconMaterialFixture(): array
{
    [$user, , , , $mo] = qcFixture();
    $uom = Uom::create(['company_id' => 1, 'code' => 'PCS'.substr(uniqid(), -3), 'name' => 'Pcs']);
    $material = Material::create([
        'company_id' => 1, 'code' => 'TRM-'.uniqid(), 'name' => 'Kancing',
        'type' => 'TRIM', 'tracking_level' => 'LOT', 'use_uom_id' => $uom->id,
    ]);
    $warehouse = Warehouse::create(['company_id' => 1, 'code' => 'RM-'.substr(uniqid(), -3), 'name' => 'RM', 'type' => 'RM']);
    $vendor = Supplier::create(['company_id' => 1, 'code' => 'SUB-'.uniqid(), 'name' => 'CMT Jaya', 'type' => 'SUBCON', 'is_active' => true]);
    app(InventoryTransactionService::class)->post('OPENING', [
        'company_id' => 1, 'source_document_type' => 'tests', 'source_document_id' => random_int(1000, 999999),
    ], [[
        'material_id' => $material->id, 'warehouse_id' => $warehouse->id,
        'qty' => 500, 'uom_id' => $uom->id, 'unit_cost' => 0.2, 'ownership' => 'COMPANY',
    ]], $user);
    $balance = StockBalance::withoutGlobalScopes()->where('material_id', $material->id)->where('warehouse_id', $warehouse->id)->firstOrFail();

    return [$user, $mo, $vendor, $material, $uom, $warehouse, $balance];
}

test('BR-090 round trip is traceable, company-owned, quantity-safe, and idempotent', function () {
    [$user, $mo, $vendor, $material, $uom, $warehouse, $balance] = subconMaterialFixture();
    $service = app(SubconService::class);
    $payload = [[
        'stock_balance_id' => $balance->id,
        'material_id' => $material->id,
        'qty_sent' => 100,
        'uom_id' => $uom->id,
    ]];
    $header = [
        'client_reference' => 'jw-test-'.uniqid(),
        'warehouse_id' => $warehouse->id,
        'fee_per_pcs' => 0.5,
    ];

    $order = $service->createAndSend(1, $mo, $vendor->id, $payload, $header, $user);
    $sameOrder = $service->createAndSend(1, $mo, $vendor->id, $payload, $header, $user);

    expect($sameOrder->id)->toBe($order->id)
        ->and($order->status)->toBe('SENT')
        ->and(StockMovement::withoutGlobalScopes()->where('movement_type', 'SUBCON_OUT')->where('source_document_id', $order->id)->count())->toBe(1);

    $balance->refresh();
    expect((float) $balance->on_hand)->toBe(400.0)
        ->and((float) $balance->in_transit_subcon)->toBe(100.0);

    $line = $order->lines->first();
    $receipt = 'receipt-test-'.uniqid();
    $returns = [[
        'line_id' => $line->id,
        'qty_returned' => 60,
        'warehouse_id' => $warehouse->id,
        'receipt_reference' => $receipt,
    ]];
    $partial = $service->receive($order->fresh(), $returns, $user);
    $duplicate = $service->receive($partial->fresh(), $returns, $user);

    expect($duplicate->status)->toBe('PARTIAL_RETURNED')
        ->and((float) $duplicate->lines->first()->qty_returned)->toBe(60.0)
        ->and($duplicate->fees->count())->toBe(1);

    $completed = $service->receive($duplicate->fresh(), [[
        'line_id' => $line->id,
        'qty_returned' => 40,
        'warehouse_id' => $warehouse->id,
        'receipt_reference' => 'receipt-final-'.uniqid(),
    ]], $user);
    expect($completed->status)->toBe('RETURNED');

    $balance->refresh();
    expect((float) $balance->on_hand)->toBe(500.0)
        ->and((float) $balance->in_transit_subcon)->toBe(0.0);

    $lineage = $service->lineage($completed, $user);
    expect($lineage['subcon_order']['production_order']['id'])->toBe($mo->id)
        ->and($lineage['outbound_movement']['movement_type'])->toBe('SUBCON_OUT')
        ->and($lineage['receipts'])->toHaveCount(2)
        ->and($lineage['authorities']['loss_yield_scrap'])->toBe('NOT_DEFINED');
});

test('subcon rejects inactive vendor, insufficient stock, and a cross-warehouse return', function () {
    [$user, $mo, $vendor, $material, $uom, $warehouse, $balance] = subconMaterialFixture();
    $service = app(SubconService::class);
    $payload = [[
        'stock_balance_id' => $balance->id,
        'material_id' => $material->id,
        'qty_sent' => 600,
        'uom_id' => $uom->id,
    ]];

    expect(fn () => $service->createAndSend(1, $mo, $vendor->id, $payload, ['warehouse_id' => $warehouse->id, 'fee_per_pcs' => 1], $user))
        ->toThrow(RuntimeException::class, 'melebihi eligible stock');

    $vendor->update(['is_active' => false]);
    $payload[0]['qty_sent'] = 50;
    expect(fn () => $service->createAndSend(1, $mo, $vendor->id, $payload, ['warehouse_id' => $warehouse->id, 'fee_per_pcs' => 1], $user))
        ->toThrow(RuntimeException::class, 'Supplier aktif');

    $vendor->update(['is_active' => true]);
    $order = $service->createAndSend(1, $mo, $vendor->id, $payload, ['warehouse_id' => $warehouse->id, 'fee_per_pcs' => 1], $user);
    $otherWarehouse = Warehouse::create(['company_id' => 1, 'code' => 'WIP-'.substr(uniqid(), -3), 'name' => 'WIP', 'type' => 'WIP']);

    expect(fn () => $service->receive($order->fresh(), [[
        'line_id' => $order->lines->first()->id,
        'qty_returned' => 10,
        'warehouse_id' => $otherWarehouse->id,
        'receipt_reference' => 'wrong-wh-'.uniqid(),
    ]], $user))->toThrow(RuntimeException::class, 'source warehouse');
});

test('returned subcontract order is immutable without a defined reversal', function () {
    [$user, $mo, $vendor, $material, $uom, $warehouse, $balance] = subconMaterialFixture();
    $service = app(SubconService::class);
    $order = $service->createAndSend(1, $mo, $vendor->id, [[
        'stock_balance_id' => $balance->id,
        'material_id' => $material->id,
        'qty_sent' => 10,
        'uom_id' => $uom->id,
    ]], ['warehouse_id' => $warehouse->id, 'fee_per_pcs' => 1], $user);
    $line = $order->lines->first();
    $order = $service->receive($order->fresh(), [[
        'line_id' => $line->id,
        'qty_returned' => 10,
        'warehouse_id' => $warehouse->id,
        'receipt_reference' => 'complete-'.uniqid(),
    ]], $user);

    expect(fn () => $service->receive($order->fresh(), [[
        'line_id' => $line->id,
        'qty_returned' => 1,
        'warehouse_id' => $warehouse->id,
        'receipt_reference' => 'late-'.uniqid(),
    ]], $user))->toThrow(RuntimeException::class, 'tidak bisa menerima return');
});
