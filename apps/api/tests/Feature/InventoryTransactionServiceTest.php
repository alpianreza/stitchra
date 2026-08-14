<?php

use Modules\Core\Models\User;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockLedger;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\MasterData\Models\Material;
use Modules\MasterData\Models\Uom;
use Modules\MasterData\Models\Warehouse;

/** Fixture: item + gudang + stok awal via ITS OPENING */
function itsFixture(float $openingQty = 0, float $openingCost = 10.0): array
{
    $user = User::factory()->create(['company_id' => 1]);
    $material = Material::create(['company_id' => 1, 'code' => 'FAB-'.uniqid(), 'name' => 'Poplin', 'type' => 'FABRIC', 'tracking_level' => 'LOT']);
    $uom = Uom::create(['company_id' => 1, 'code' => 'MTR'.substr(uniqid(), -3), 'name' => 'Meter']);
    $warehouse = Warehouse::create(['company_id' => 1, 'code' => 'RM-'.substr(uniqid(), -3), 'name' => 'Gudang Kain', 'type' => 'RM']);

    $its = app(InventoryTransactionService::class);

    if ($openingQty > 0) {
        $its->post('OPENING', [
            'company_id' => 1, 'source_document_type' => 'tests', 'source_document_id' => 1,
        ], [[
            'material_id' => $material->id, 'warehouse_id' => $warehouse->id,
            'qty' => $openingQty, 'uom_id' => $uom->id, 'unit_cost' => $openingCost,
        ]], $user);
    }

    return [$user, $material, $uom, $warehouse, $its];
}

function balanceOf(int $materialId, int $warehouseId): StockBalance
{
    return StockBalance::withoutGlobalScopes()
        ->where('material_id', $materialId)->where('warehouse_id', $warehouseId)
        ->firstOrFail();
}

test('BR-004: penerimaan masuk QUALITY_HOLD — available tetap nol', function () {
    [$user, $material, $uom, $warehouse, $its] = itsFixture();

    $its->post('PURCHASE_RECEIPT', [
        'company_id' => 1, 'source_document_type' => 'goods_receipts', 'source_document_id' => 1,
    ], [[
        'material_id' => $material->id, 'warehouse_id' => $warehouse->id,
        'qty' => 100, 'uom_id' => $uom->id, 'unit_cost' => 12.5,
    ]], $user);

    $b = balanceOf($material->id, $warehouse->id);
    expect((float) $b->on_hand)->toBe(100.0);
    expect((float) $b->quality_hold)->toBe(100.0);
    expect($b->available())->toBe(0.0);   // BR-006: hold tidak available

    // Ledger append-only tercatat
    $ledger = StockLedger::withoutGlobalScopes()->where('material_id', $material->id)->firstOrFail();
    expect($ledger->movement_type)->toBe('PURCHASE_RECEIPT');
    expect((float) $ledger->qty_in)->toBe(100.0);
});

test('BR-004: release quality hold memindahkan ke available tanpa mengubah on_hand', function () {
    [$user, $material, $uom, $warehouse, $its] = itsFixture();

    $its->post('PURCHASE_RECEIPT', [
        'company_id' => 1, 'source_document_type' => 'goods_receipts', 'source_document_id' => 1,
    ], [[
        'material_id' => $material->id, 'warehouse_id' => $warehouse->id,
        'qty' => 100, 'uom_id' => $uom->id, 'unit_cost' => 12.5,
    ]], $user);

    $its->releaseQualityHold(1, [
        'material_id' => $material->id, 'warehouse_id' => $warehouse->id, 'uom_id' => $uom->id,
        'source_document_id' => 1,
    ], 60, $user);

    $b = balanceOf($material->id, $warehouse->id);
    expect((float) $b->on_hand)->toBe(100.0);
    expect((float) $b->quality_hold)->toBe(40.0);
    expect($b->available())->toBe(60.0);
});

test('BR-006/BR-013: issue melebihi available DITOLAK dan saldo TIDAK berubah (atomic)', function () {
    [$user, $material, $uom, $warehouse, $its] = itsFixture(openingQty: 50, openingCost: 10);

    $before = StockLedger::withoutGlobalScopes()->where('material_id', $material->id)->count();

    try {
        $its->post('MATERIAL_ISSUE', [
            'company_id' => 1, 'source_document_type' => 'material_issues', 'source_document_id' => 1,
        ], [[
            'material_id' => $material->id, 'warehouse_id' => $warehouse->id,
            'qty' => 51, 'uom_id' => $uom->id,   // melebihi available 50
        ]], $user);
        $this->fail('Seharusnya RuntimeException');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('stok tidak cukup');
    }

    // Rollback total: saldo & ledger persis seperti sebelumnya (FASE 20)
    $b = balanceOf($material->id, $warehouse->id);
    expect((float) $b->on_hand)->toBe(50.0);
    expect(StockLedger::withoutGlobalScopes()->where('material_id', $material->id)->count())->toBe($before);
});

test('BR-006: dokumen multi-line gagal di tengah ⇒ SELURUH dokumen rollback', function () {
    [$user, $material, $uom, $warehouse, $its] = itsFixture(openingQty: 10, openingCost: 10);
    $material2 = Material::create(['company_id' => 1, 'code' => 'TRM-'.uniqid(), 'name' => 'Button', 'type' => 'TRIM']);

    // Line 1 valid (issue 5), line 2 invalid (issue 999 dari stok 0)
    try {
        $its->post('MATERIAL_ISSUE', [
            'company_id' => 1, 'source_document_type' => 'material_issues', 'source_document_id' => 2,
        ], [
            ['material_id' => $material->id, 'warehouse_id' => $warehouse->id, 'qty' => 5, 'uom_id' => $uom->id],
            ['material_id' => $material2->id, 'warehouse_id' => $warehouse->id, 'qty' => 999, 'uom_id' => $uom->id],
        ], $user);
        $this->fail('Seharusnya RuntimeException');
    } catch (RuntimeException $e) {
        // expected
    }

    // Line 1 juga TIDAK ter-posting — atomicity (BR-013)
    $b = balanceOf($material->id, $warehouse->id);
    expect((float) $b->on_hand)->toBe(10.0);
    expect(StockBalance::withoutGlobalScopes()->where('material_id', $material2->id)->count())->toBe(0);
});

test('BR-005: moving average dua penerimaan harga berbeda', function () {
    [$user, $material, $uom, $warehouse, $its] = itsFixture();

    // OPENING tidak menambah hold; gunakan OPENING untuk dua kali penerimaan berbiaya
    $its->post('OPENING', ['company_id' => 1, 'source_document_type' => 'tests', 'source_document_id' => 1],
        [['material_id' => $material->id, 'warehouse_id' => $warehouse->id, 'qty' => 100, 'uom_id' => $uom->id, 'unit_cost' => 10.0]], $user);
    $its->post('OPENING', ['company_id' => 1, 'source_document_type' => 'tests', 'source_document_id' => 2],
        [['material_id' => $material->id, 'warehouse_id' => $warehouse->id, 'qty' => 100, 'uom_id' => $uom->id, 'unit_cost' => 20.0]], $user);

    // avg = (100×10 + 100×20) / 200 = 15
    $b = balanceOf($material->id, $warehouse->id);
    expect((float) $b->on_hand)->toBe(200.0);
    expect((float) $b->avg_cost)->toBe(15.0);

    // Ledger menyimpan cost per transaksi (BR-005: bukan hanya agregat)
    $entries = StockLedger::withoutGlobalScopes()->where('material_id', $material->id)->orderBy('id')->get();
    expect($entries)->toHaveCount(2);
    expect((float) $entries[0]->unit_cost)->toBe(10.0);
    expect((float) $entries[1]->unit_cost)->toBe(20.0);
});

test('BR-017: adjustment negatif yang membuat stok minus ditolak; positif dengan cost masuk moving average', function () {
    [$user, $material, $uom, $warehouse, $its] = itsFixture(openingQty: 20, openingCost: 10);

    // Negatif berlebih → tolak
    $its->adjust(1, ['material_id' => $material->id, 'warehouse_id' => $warehouse->id, 'uom_id' => $uom->id], -25, 'stock_adjustments', 1, $user);
})->throws(RuntimeException::class);

test('adjustment positif dengan cost mengoreksi moving average dengan benar', function () {
    [$user, $material, $uom, $warehouse, $its] = itsFixture(openingQty: 20, openingCost: 10);

    // +20 @ 20 → avg = (20×10 + 20×20)/40 = 15
    $its->adjust(1, ['material_id' => $material->id, 'warehouse_id' => $warehouse->id, 'uom_id' => $uom->id, 'unit_cost' => 20.0], 20, 'stock_adjustments', 2, $user);

    $b = balanceOf($material->id, $warehouse->id);
    expect((float) $b->on_hand)->toBe(40.0);
    expect((float) $b->avg_cost)->toBe(15.0);
});

test('BR-013: dokumen movement mendapat nomor dari numbering service (BR-010)', function () {
    [$user, $material, $uom, $warehouse, $its] = itsFixture();

    $m1 = $its->post('OPENING', ['company_id' => 1, 'source_document_type' => 'tests', 'source_document_id' => 1],
        [['material_id' => $material->id, 'warehouse_id' => $warehouse->id, 'qty' => 1, 'uom_id' => $uom->id]], $user);
    $m2 = $its->post('OPENING', ['company_id' => 1, 'source_document_type' => 'tests', 'source_document_id' => 2],
        [['material_id' => $material->id, 'warehouse_id' => $warehouse->id, 'qty' => 1, 'uom_id' => $uom->id]], $user);

    $year = now()->year;
    expect($m1->doc_no)->toBe("ADJ-{$year}-000001");   // OPENING → prefix ADJ (mapping ITS)
    expect($m2->doc_no)->toBe("ADJ-{$year}-000002");
});
