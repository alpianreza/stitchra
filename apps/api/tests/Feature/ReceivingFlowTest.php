<?php

use Modules\Core\Models\User;
use Modules\MasterData\Models\Material;
use Modules\MasterData\Models\Supplier;
use Modules\MasterData\Models\Uom;
use Modules\MasterData\Models\Warehouse;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Services\PurchasingService;
use Modules\Receiving\Models\FabricRoll;
use Modules\Receiving\Services\InwardQcService;
use Modules\Receiving\Services\ReceivingService;
use Modules\Inventory\Models\StockBalance;

/** Fixture: PO approved untuk fabric roll-tracked */
function grFixture(): array
{
    $user = User::factory()->create(['company_id' => 1]);
    $uomKg = Uom::create(['company_id' => 1, 'code' => 'KG', 'name' => 'Kilogram']);
    $uomMtr = Uom::create(['company_id' => 1, 'code' => 'MTR'.substr(uniqid(), -3), 'name' => 'Meter']);

    // Fabric GSM 180, lebar 150 cm → 1 kg = 1000/(180×1.5) = 3.7037 m
    $fabric = Material::create([
        'company_id' => 1, 'code' => 'FAB-'.uniqid(), 'name' => 'Jersey', 'type' => 'FABRIC',
        'gsm' => 180, 'width_cm' => 150, 'tracking_level' => 'ROLL',
        'buy_uom_id' => $uomKg->id, 'use_uom_id' => $uomMtr->id,
    ]);

    $supplier = Supplier::create(['company_id' => 1, 'code' => 'SUP-'.uniqid(), 'name' => 'Textile Co', 'type' => 'FABRIC']);
    $warehouse = Warehouse::create(['company_id' => 1, 'code' => 'RM-'.substr(uniqid(), -3), 'name' => 'Gudang Kain', 'type' => 'RM']);

    $po = app(PurchasingService::class)->createPo(1, [
        'supplier_id' => $supplier->id, 'order_date' => now()->toDateString(),
    ], [[
        'material_id' => $fabric->id, 'qty' => 100, 'uom_id' => $uomKg->id, 'unit_price' => 12.0,
    ]], $user);
    $po->update(['status' => 'APPROVED']);

    return [$user, $fabric, $uomKg, $uomMtr, $supplier, $warehouse, $po];
}

test('BR-052: GR fabric TANPA roll ditolak', function () {
    [$user, $fabric, $uomKg, , , $warehouse, $po] = grFixture();

    app(ReceivingService::class)->createAndPost(1, [
        'purchase_order_id' => $po->id, 'warehouse_id' => $warehouse->id, 'received_date' => now()->toDateString(),
    ], [[
        'po_line_id' => $po->lines->first()->id, 'material_id' => $fabric->id,
        'qty_received' => 100, 'uom_id' => $uomKg->id, 'unit_price' => 12.0,
        // tanpa rolls → harus error
    ]], $user);
})->throws(RuntimeException::class);

test('BR-002/004/052: GR roll-level tersimpan dengan konversi, stok masuk QUALITY_HOLD', function () {
    [$user, $fabric, $uomKg, , , $warehouse, $po] = grFixture();

    $gr = app(ReceivingService::class)->createAndPost(1, [
        'purchase_order_id' => $po->id, 'warehouse_id' => $warehouse->id, 'received_date' => now()->toDateString(),
    ], [[
        'po_line_id' => $po->lines->first()->id, 'material_id' => $fabric->id,
        'qty_received' => 100, 'uom_id' => $uomKg->id, 'unit_price' => 12.0,
        'rolls' => [
            ['roll_no' => 'R001', 'qty_buy' => 60, 'qty_meter_actual' => 220],
            ['roll_no' => 'R002', 'qty_buy' => 40, 'qty_meter_actual' => 150],
        ],
    ]], $user);

    // Roll tercatat (BR-003/052)
    $rolls = FabricRoll::where('company_id', 1)->orderBy('roll_no')->get();
    expect($rolls)->toHaveCount(2);
    expect($rolls[0]->roll_no)->toBe('R001');
    expect($rolls[0]->status)->toBe('QUALITY_HOLD');
    expect((float) $rolls[0]->qty_remaining_meter)->toBe(220.0);
    // Konversi per roll tersimpan (BR-002): 1 kg = 1000/(180×1.5) ≈ 3.7037
    expect((float) $rolls[0]->conversion_rate)->toBeGreaterThan(3.70)->toBeLessThan(3.71);

    // Stok masuk QUALITY_HOLD (BR-004)
    $b = StockBalance::withoutGlobalScopes()->where('material_id', $fabric->id)->where('warehouse_id', $warehouse->id)->firstOrFail();
    expect((float) $b->on_hand)->toBe(100.0);
    expect((float) $b->quality_hold)->toBe(100.0);
    expect($b->available())->toBe(0.0);

    // BR-051: PO full received
    expect($po->fresh()->status)->toBe('RECEIVED');
    expect((float) $po->fresh()->lines->first()->received_qty)->toBe(100.0);
});

test('BR-051: partial receiving → PO PARTIAL_RECEIVED', function () {
    [$user, $fabric, $uomKg, , , $warehouse, $po] = grFixture();

    app(ReceivingService::class)->createAndPost(1, [
        'purchase_order_id' => $po->id, 'warehouse_id' => $warehouse->id, 'received_date' => now()->toDateString(),
    ], [[
        'po_line_id' => $po->lines->first()->id, 'material_id' => $fabric->id,
        'qty_received' => 40, 'uom_id' => $uomKg->id, 'unit_price' => 12.0,
        'rolls' => [['roll_no' => 'R010', 'qty_buy' => 40, 'qty_meter_actual' => 148]],
    ]], $user);

    expect($po->fresh()->status)->toBe('PARTIAL_RECEIVED');
});

test('BR-004: inspeksi PASS → release hold; FAIL → roll REJECTED + return memposting PURCHASE_RETURN', function () {
    [$user, $fabric, $uomKg, , $supplier, $warehouse, $po] = grFixture();
    $receiving = app(ReceivingService::class);
    $qc = app(InwardQcService::class);

    $gr = $receiving->createAndPost(1, [
        'purchase_order_id' => $po->id, 'warehouse_id' => $warehouse->id, 'received_date' => now()->toDateString(),
    ], [[
        'po_line_id' => $po->lines->first()->id, 'material_id' => $fabric->id,
        'qty_received' => 100, 'uom_id' => $uomKg->id, 'unit_price' => 12.0,
        'rolls' => [
            ['roll_no' => 'R100', 'qty_buy' => 60, 'qty_meter_actual' => 220],
            ['roll_no' => 'R101', 'qty_buy' => 40, 'qty_meter_actual' => 150],
        ],
    ]], $user);

    $grLine = $gr->lines->first();
    $rolls = FabricRoll::where('gr_line_id', $grLine->id)->orderBy('roll_no')->get();

    $inspection = $qc->create(1, $gr, [
        ['gr_line_id' => $grLine->id, 'roll_id' => $rolls[0]->id, 'result' => 'PASS', 'four_point_points' => 12, 'gsm_actual' => 181, 'shade_verdict' => 'MATCH'],
        ['gr_line_id' => $grLine->id, 'roll_id' => $rolls[1]->id, 'result' => 'FAIL', 'four_point_points' => 55, 'shade_verdict' => 'DEVIATION'],
    ], $user);

    expect($inspection->result)->toBe('PARTIAL');

    // Finalize: PASS 60kg release; FAIL 40kg reject
    $qc->finalize($inspection, [
        ['gr_line_id' => $grLine->id, 'roll_id' => $rolls[0]->id, 'result' => 'PASS', 'material_id' => $fabric->id, 'warehouse_id' => $warehouse->id, 'qty' => 60, 'uom_id' => $uomKg->id],
        ['gr_line_id' => $grLine->id, 'roll_id' => $rolls[1]->id, 'result' => 'FAIL', 'material_id' => $fabric->id, 'warehouse_id' => $warehouse->id, 'qty' => 40, 'uom_id' => $uomKg->id],
    ], $user);

    $b = StockBalance::withoutGlobalScopes()->where('material_id', $fabric->id)->where('warehouse_id', $warehouse->id)->firstOrFail();
    expect((float) $b->quality_hold)->toBe(40.0);
    expect($b->available())->toBe(60.0);
    expect($rolls[0]->fresh()->status)->toBe('RELEASED');
    expect($rolls[1]->fresh()->status)->toBe('REJECTED_RETURNED');

    // Supplier return untuk roll gagal → stok keluar dari hold (PURCHASE_RETURN)
    $qc->returnGoods(1, $gr, [[
        'material_id' => $fabric->id, 'roll_id' => $rolls[1]->id,
        'qty' => 40, 'uom_id' => $uomKg->id, 'unit_cost' => 12.0, 'gr_line_id' => $grLine->id,
    ]], 'Shade deviation berat', $user);

    $b = StockBalance::withoutGlobalScopes()->where('material_id', $fabric->id)->where('warehouse_id', $warehouse->id)->firstOrFail();
    expect((float) $b->on_hand)->toBe(60.0);
    expect((float) $b->quality_hold)->toBe(0.0);
    expect($b->available())->toBe(60.0);
});

test('BR-050: 3-way match — MATCHED bila sesuai, MISMATCH bila harga melebihi toleransi', function () {
    [$user, $fabric, $uomKg, , $supplier, $warehouse, $po] = grFixture();

    // Terima penuh dulu
    app(ReceivingService::class)->createAndPost(1, [
        'purchase_order_id' => $po->id, 'warehouse_id' => $warehouse->id, 'received_date' => now()->toDateString(),
    ], [[
        'po_line_id' => $po->lines->first()->id, 'material_id' => $fabric->id,
        'qty_received' => 100, 'uom_id' => $uomKg->id, 'unit_price' => 12.0,
        'rolls' => [['roll_no' => 'R200', 'qty_buy' => 100, 'qty_meter_actual' => 370]],
    ]], $user);

    $invoice = \Modules\Purchasing\Models\SupplierInvoice::create([
        'company_id' => 1, 'doc_no' => 'INV-TEST-1', 'supplier_id' => $supplier->id,
        'purchase_order_id' => $po->id, 'invoice_date' => now()->toDateString(),
        'total_amount' => 1200, 'created_by' => $user->id,
    ]);
    $invoice->lines()->create([
        'po_line_id' => $po->lines->first()->id, 'qty' => 100, 'unit_price' => 12.0, 'amount' => 1200,
    ]);

    $matcher = app(\Modules\Purchasing\Services\ThreeWayMatchService::class);
    expect($matcher->match($invoice->fresh(), 2.0, 2.0)->match_status)->toBe('MATCHED');

    // Invoice kedua: harga 13 (8.3% di atas PO) → MISMATCH dengan toleransi 2%
    $invoice2 = \Modules\Purchasing\Models\SupplierInvoice::create([
        'company_id' => 1, 'doc_no' => 'INV-TEST-2', 'supplier_id' => $supplier->id,
        'purchase_order_id' => $po->id, 'invoice_date' => now()->toDateString(),
        'total_amount' => 1300, 'created_by' => $user->id,
    ]);
    $invoice2->lines()->create([
        'po_line_id' => $po->lines->first()->id, 'qty' => 100, 'unit_price' => 13.0, 'amount' => 1300,
    ]);

    expect($matcher->match($invoice2->fresh(), 2.0, 2.0)->match_status)->toBe('MISMATCH');
});
