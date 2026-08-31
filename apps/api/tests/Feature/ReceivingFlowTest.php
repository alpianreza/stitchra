<?php

use Modules\Core\Models\User;
use Modules\Inventory\Models\StockBalance;
use Modules\MasterData\Models\Material;
use Modules\MasterData\Models\Supplier;
use Modules\MasterData\Models\Uom;
use Modules\MasterData\Models\Warehouse;
use Modules\Purchasing\Models\SupplierInvoice;
use Modules\Purchasing\Services\PurchasingService;
use Modules\Purchasing\Services\ThreeWayMatchService;
use Modules\Receiving\Models\FabricRoll;
use Modules\Receiving\Services\InwardQcService;
use Modules\Receiving\Services\ReceivingService;

function grFixture(): array
{
    $user = User::factory()->create(['company_id' => 1]);
    $uomKg = Uom::create(['company_id' => 1, 'code' => 'KG'.substr(uniqid(), -4), 'name' => 'Kilogram']);
    $uomMtr = Uom::create(['company_id' => 1, 'code' => 'MTR'.substr(uniqid(), -3), 'name' => 'Meter']);
    $fabric = Material::create(['company_id' => 1, 'code' => 'FAB-'.uniqid(), 'name' => 'Jersey', 'type' => 'FABRIC', 'gsm' => 180, 'width_cm' => 150, 'tracking_level' => 'ROLL', 'buy_uom_id' => $uomKg->id, 'use_uom_id' => $uomMtr->id]);
    $supplier = Supplier::create(['company_id' => 1, 'code' => 'SUP-'.uniqid(), 'name' => 'Textile Co', 'type' => 'FABRIC']);
    $warehouse = Warehouse::create(['company_id' => 1, 'code' => 'RM-'.substr(uniqid(), -3), 'name' => 'Gudang Kain', 'type' => 'RM']);
    $po = app(PurchasingService::class)->createPo(1, ['supplier_id' => $supplier->id, 'order_date' => now()->toDateString()], [['material_id' => $fabric->id, 'qty' => 100, 'uom_id' => $uomKg->id, 'unit_price' => 12.0]], $user);
    $po->update(['status' => 'APPROVED']);
    return [$user, $fabric, $uomKg, $uomMtr, $supplier, $warehouse, $po];
}

function rollBalanceTotals(int $materialId, int $warehouseId): array
{
    $balances = StockBalance::withoutGlobalScopes()->where('material_id', $materialId)->where('warehouse_id', $warehouseId)->get();
    return [(float) $balances->sum(fn ($b) => (float) $b->on_hand), (float) $balances->sum(fn ($b) => (float) $b->quality_hold), (float) $balances->sum(fn ($b) => $b->available())];
}

test('BR-052: GR fabric tanpa roll ditolak', function () {
    [$user, , , , , $warehouse, $po] = grFixture();
    app(ReceivingService::class)->createAndPost(1, ['purchase_order_id' => $po->id, 'warehouse_id' => $warehouse->id, 'received_date' => now()->toDateString()], [['po_line_id' => $po->lines->first()->id, 'qty_received' => 100]], $user);
})->throws(RuntimeException::class);

test('BR-002/004/052: saldo roll memakai meter dan valuation tetap dari UOM beli', function () {
    [$user, $fabric, , , , $warehouse, $po] = grFixture();
    app(ReceivingService::class)->createAndPost(1, ['purchase_order_id' => $po->id, 'warehouse_id' => $warehouse->id, 'received_date' => now()->toDateString()], [[
        'po_line_id' => $po->lines->first()->id, 'qty_received' => 100,
        'rolls' => [['roll_no' => 'R001', 'qty_buy' => 60, 'qty_meter_actual' => 220], ['roll_no' => 'R002', 'qty_buy' => 40, 'qty_meter_actual' => 150]],
    ]], $user);
    $rolls = FabricRoll::where('company_id', 1)->orderBy('roll_no')->get();
    [$onHand, $hold, $available] = rollBalanceTotals($fabric->id, $warehouse->id);
    expect($rolls)->toHaveCount(2)->and($onHand)->toBe(370.0)->and($hold)->toBe(370.0)->and($available)->toBe(0.0)
        ->and((float) $rolls[0]->conversion_rate)->toBeGreaterThan(3.70)->toBeLessThan(3.71)
        ->and($po->fresh()->status)->toBe('RECEIVED');
    $totalValue = StockBalance::withoutGlobalScopes()->where('material_id', $fabric->id)->get()->sum(fn ($b) => (float) $b->on_hand * (float) $b->avg_cost);
    expect(round($totalValue, 2))->toBe(1200.0);
});

test('BR-051: partial receiving membuat PO PARTIAL_RECEIVED', function () {
    [$user, , , , , $warehouse, $po] = grFixture();
    app(ReceivingService::class)->createAndPost(1, ['purchase_order_id' => $po->id, 'warehouse_id' => $warehouse->id, 'received_date' => now()->toDateString()], [[
        'po_line_id' => $po->lines->first()->id, 'qty_received' => 40, 'rolls' => [['roll_no' => 'R010', 'qty_buy' => 40, 'qty_meter_actual' => 148]],
    ]], $user);
    expect($po->fresh()->status)->toBe('PARTIAL_RECEIVED');
});

test('BR-004: QC dan return bekerja pada saldo meter per roll', function () {
    [$user, $fabric, , , , $warehouse, $po] = grFixture();
    $gr = app(ReceivingService::class)->createAndPost(1, ['purchase_order_id' => $po->id, 'warehouse_id' => $warehouse->id, 'received_date' => now()->toDateString()], [[
        'po_line_id' => $po->lines->first()->id, 'qty_received' => 100,
        'rolls' => [['roll_no' => 'R100', 'qty_buy' => 60, 'qty_meter_actual' => 220], ['roll_no' => 'R101', 'qty_buy' => 40, 'qty_meter_actual' => 150]],
    ]], $user);
    $grLine = $gr->lines->first(); $rolls = FabricRoll::where('gr_line_id', $grLine->id)->orderBy('roll_no')->get();
    $qc = app(InwardQcService::class);
    $inspection = $qc->create(1, $gr, [['gr_line_id' => $grLine->id, 'roll_id' => $rolls[0]->id, 'result' => 'PASS'], ['gr_line_id' => $grLine->id, 'roll_id' => $rolls[1]->id, 'result' => 'FAIL']], $user);
    $qc->finalize($inspection, [], $user);
    [$onHand, $hold, $available] = rollBalanceTotals($fabric->id, $warehouse->id);
    expect($onHand)->toBe(370.0)->and($hold)->toBe(150.0)->and($available)->toBe(220.0)->and($grLine->fresh()->status)->toBe('PARTIAL');
    $qc->returnGoods(1, $gr, [['gr_line_id' => $grLine->id, 'roll_id' => $rolls[1]->id]], 'Reject', $user);
    [$onHand, $hold, $available] = rollBalanceTotals($fabric->id, $warehouse->id);
    expect($onHand)->toBe(220.0)->and($hold)->toBe(0.0)->and($available)->toBe(220.0);
});

test('BR-050: 3-way match membedakan harga sesuai dan di luar tolerance', function () {
    [$user, , , , $supplier, $warehouse, $po] = grFixture();
    app(ReceivingService::class)->createAndPost(1, ['purchase_order_id' => $po->id, 'warehouse_id' => $warehouse->id, 'received_date' => now()->toDateString()], [[
        'po_line_id' => $po->lines->first()->id, 'qty_received' => 100, 'rolls' => [['roll_no' => 'R200', 'qty_buy' => 100, 'qty_meter_actual' => 370]],
    ]], $user);
    $make = function (string $doc, float $price) use ($user, $supplier, $po) {
        $invoice = SupplierInvoice::create(['company_id' => 1, 'doc_no' => $doc, 'supplier_id' => $supplier->id, 'purchase_order_id' => $po->id, 'invoice_date' => now()->toDateString(), 'total_amount' => 100 * $price, 'created_by' => $user->id]);
        $invoice->lines()->create(['po_line_id' => $po->lines->first()->id, 'qty' => 100, 'unit_price' => $price, 'amount' => 100 * $price]); return $invoice;
    };
    $matcher = app(ThreeWayMatchService::class);
    expect($matcher->match($make('INV-1', 12), 2, 2)->match_status)->toBe('MATCHED')->and($matcher->match($make('INV-2', 13), 2, 2)->match_status)->toBe('MISMATCH');
});
