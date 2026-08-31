<?php

use LogicException;
use Mockery;
use Modules\Core\Approval\ApprovalEngine;
use Modules\Core\Models\User;
use Modules\Core\Services\NumberingService;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockLedger;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\MasterData\Models\Material;
use Modules\MasterData\Models\Supplier;
use Modules\MasterData\Models\Uom;
use Modules\MasterData\Models\Warehouse;
use Modules\Purchasing\Services\PurchasingService;
use Modules\Receiving\Models\GoodsReceipt;
use Modules\Receiving\Services\ReceivingService;
use RuntimeException;

function stage4InventoryFixture(): array
{
    $user = User::factory()->create(['company_id' => 1]);
    $material = Material::create([
        'company_id' => 1,
        'code' => 'S4-MAT-'.uniqid(),
        'name' => 'Button',
        'type' => 'TRIM',
        'tracking_level' => 'LOT',
    ]);
    $uom = Uom::create(['company_id' => 1, 'code' => 'S4U'.substr(uniqid(), -6), 'name' => 'Piece']);
    $warehouse = Warehouse::create([
        'company_id' => 1,
        'code' => 'S4W'.substr(uniqid(), -6),
        'name' => 'Raw Material',
        'type' => 'RM',
    ]);

    return compact('user', 'material', 'uom', 'warehouse') + ['its' => app(InventoryTransactionService::class)];
}

function stage4ApprovedPo(float $qty = 10, float $price = 12): array
{
    $fixture = stage4InventoryFixture();
    $supplier = Supplier::create([
        'company_id' => 1,
        'code' => 'S4S'.substr(uniqid(), -6),
        'name' => 'Supplier',
        'type' => 'TRIM',
    ]);

    $po = app(PurchasingService::class)->createPo(1, [
        'supplier_id' => $supplier->id,
        'order_date' => '2026-08-31',
    ], [[
        'material_id' => $fixture['material']->id,
        'qty' => $qty,
        'uom_id' => $fixture['uom']->id,
        'unit_price' => $price,
    ]], $fixture['user']);
    $po->update(['status' => 'APPROVED']);

    return $fixture + compact('supplier', 'po');
}

test('ITS menolak movement type yang tidak didukung oleh post', function () {
    $f = stage4InventoryFixture();

    $f['its']->post('QUALITY_RELEASE', [
        'company_id' => 1,
        'source_document_type' => 'tests',
        'source_document_id' => 1,
    ], [[
        'material_id' => $f['material']->id,
        'warehouse_id' => $f['warehouse']->id,
        'qty' => 1,
        'uom_id' => $f['uom']->id,
    ]], $f['user']);
})->throws(RuntimeException::class, 'tidak didukung');

test('ITS idempotent untuk movement type dan source document yang sama', function () {
    $f = stage4InventoryFixture();
    $header = ['company_id' => 1, 'source_document_type' => 'tests', 'source_document_id' => 100];
    $lines = [[
        'material_id' => $f['material']->id,
        'warehouse_id' => $f['warehouse']->id,
        'qty' => 10,
        'uom_id' => $f['uom']->id,
        'unit_cost' => 5,
    ]];

    $first = $f['its']->post('OPENING', $header, $lines, $f['user']);
    $second = $f['its']->post('OPENING', $header, $lines, $f['user']);

    $balance = StockBalance::withoutGlobalScopes()->where('material_id', $f['material']->id)->firstOrFail();
    expect($second->id)->toBe($first->id)
        ->and((float) $balance->on_hand)->toBe(10.0)
        ->and(StockMovement::withoutGlobalScopes()->where($header)->count())->toBe(1)
        ->and(StockLedger::withoutGlobalScopes()->where($header)->count())->toBe(1);
});

test('material issue dapat mengonsumsi stok yang sudah direservasi', function () {
    $f = stage4InventoryFixture();
    $f['its']->post('OPENING', [
        'company_id' => 1, 'source_document_type' => 'tests', 'source_document_id' => 101,
    ], [[
        'material_id' => $f['material']->id, 'warehouse_id' => $f['warehouse']->id,
        'qty' => 10, 'uom_id' => $f['uom']->id,
    ]], $f['user']);

    $balance = StockBalance::withoutGlobalScopes()->where('material_id', $f['material']->id)->firstOrFail();
    $balance->update(['reserved' => 10]);

    $f['its']->post('MATERIAL_ISSUE', [
        'company_id' => 1, 'source_document_type' => 'material_issues', 'source_document_id' => 101,
    ], [[
        'material_id' => $f['material']->id, 'warehouse_id' => $f['warehouse']->id,
        'qty' => 10, 'uom_id' => $f['uom']->id,
    ]], $f['user']);

    $balance->refresh();
    expect((float) $balance->on_hand)->toBe(0.0)
        ->and((float) $balance->reserved)->toBe(0.0);
});

test('purchase return dapat mengeluarkan seluruh stok yang masih quality hold', function () {
    $f = stage4InventoryFixture();
    $f['its']->post('PURCHASE_RECEIPT', [
        'company_id' => 1, 'source_document_type' => 'goods_receipts', 'source_document_id' => 102,
    ], [[
        'material_id' => $f['material']->id, 'warehouse_id' => $f['warehouse']->id,
        'qty' => 10, 'uom_id' => $f['uom']->id, 'unit_cost' => 5,
    ]], $f['user']);

    $f['its']->post('PURCHASE_RETURN', [
        'company_id' => 1, 'source_document_type' => 'supplier_returns', 'source_document_id' => 102,
    ], [[
        'material_id' => $f['material']->id, 'warehouse_id' => $f['warehouse']->id,
        'qty' => 10, 'uom_id' => $f['uom']->id, 'unit_cost' => 5,
    ]], $f['user']);

    $balance = StockBalance::withoutGlobalScopes()->where('material_id', $f['material']->id)->firstOrFail();
    expect((float) $balance->on_hand)->toBe(0.0)
        ->and((float) $balance->quality_hold)->toBe(0.0);
});

test('stock ledger tidak dapat diubah atau dihapus melalui model', function () {
    $f = stage4InventoryFixture();
    $f['its']->post('OPENING', [
        'company_id' => 1, 'source_document_type' => 'tests', 'source_document_id' => 103,
    ], [[
        'material_id' => $f['material']->id, 'warehouse_id' => $f['warehouse']->id,
        'qty' => 1, 'uom_id' => $f['uom']->id,
    ]], $f['user']);

    $ledger = StockLedger::withoutGlobalScopes()->firstOrFail();
    expect(fn () => $ledger->update(['running_balance' => 999]))->toThrow(LogicException::class);
    expect(fn () => $ledger->delete())->toThrow(LogicException::class);
});

test('GR over receipt rollback tanpa mengubah PO line ledger atau saldo', function () {
    $f = stage4ApprovedPo(qty: 10);

    expect(fn () => app(ReceivingService::class)->createAndPost(1, [
        'purchase_order_id' => $f['po']->id,
        'warehouse_id' => $f['warehouse']->id,
        'received_date' => '2026-08-31',
    ], [[
        'po_line_id' => $f['po']->lines->first()->id,
        'qty_received' => 11,
    ]], $f['user']))->toThrow(RuntimeException::class, 'melebihi sisa');

    expect(GoodsReceipt::withoutGlobalScopes()->count())->toBe(0)
        ->and((float) $f['po']->fresh()->lines->first()->received_qty)->toBe(0.0)
        ->and(StockLedger::withoutGlobalScopes()->count())->toBe(0)
        ->and(StockBalance::withoutGlobalScopes()->count())->toBe(0);
});

test('GR mengambil material UOM dan harga dari PO line', function () {
    $f = stage4ApprovedPo(qty: 10, price: 12.5);

    $gr = app(ReceivingService::class)->createAndPost(1, [
        'purchase_order_id' => $f['po']->id,
        'warehouse_id' => $f['warehouse']->id,
        'received_date' => '2026-08-31',
    ], [[
        'po_line_id' => $f['po']->lines->first()->id,
        'qty_received' => 10,
    ]], $f['user']);

    $line = $gr->lines->first();
    $balance = StockBalance::withoutGlobalScopes()->where('material_id', $f['material']->id)->firstOrFail();
    expect($line->material_id)->toBe($f['material']->id)
        ->and($line->uom_id)->toBe($f['uom']->id)
        ->and((float) $line->unit_price)->toBe(12.5)
        ->and((float) $balance->avg_cost)->toBe(12.5);
});

test('PO submit rollback ke DRAFT bila approval gagal', function () {
    $f = stage4ApprovedPo();
    $f['po']->update(['status' => 'DRAFT']);

    $approval = Mockery::mock(ApprovalEngine::class);
    $approval->shouldReceive('submit')->once()->andThrow(new RuntimeException('approval unavailable'));
    $service = new PurchasingService(app(NumberingService::class), $approval);

    expect(fn () => $service->submitPo($f['po'], $f['user']))
        ->toThrow(RuntimeException::class, 'approval unavailable');
    expect($f['po']->fresh()->status)->toBe('DRAFT');
});
