<?php

use Modules\Core\Models\User;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockReservation;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\MasterData\Models\Color;
use Modules\MasterData\Models\Colorway;
use Modules\MasterData\Models\Customer;
use Modules\MasterData\Models\Material;
use Modules\MasterData\Models\Operation;
use Modules\MasterData\Models\Size;
use Modules\MasterData\Models\Style;
use Modules\MasterData\Models\Supplier;
use Modules\MasterData\Models\Uom;
use Modules\MasterData\Models\Warehouse;
use Modules\ProductDev\Services\BomService;
use Modules\ProductDev\Services\RoutingService;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Services\MaterialIssueService;
use Modules\Production\Services\ProductionOrderService;
use Modules\Purchasing\Services\PurchasingService;
use Modules\Receiving\Models\FabricRoll;
use Modules\Receiving\Services\ReceivingService;
use Modules\Sales\Services\SalesOrderService;

/**
 * Fixture lengkap Phase 6: style (BOM fabric 2m/pcs + trim backflush 5pcs/pcs,
 * routing 2 operasi), SO CONFIRMED 100 pcs, MO RELEASED dengan stok:
 * fabric 500 m (2 roll: R001 300m, R002 200m), trim 1000 pcs.
 */
function shopFixture(): array
{
    $user = User::factory()->create(['company_id' => 1]);
    $uomMtr = Uom::create(['company_id' => 1, 'code' => 'MTR'.substr(uniqid(), -3), 'name' => 'Meter']);
    $uomPcs = Uom::create(['company_id' => 1, 'code' => 'PCS'.substr(uniqid(), -3), 'name' => 'Pcs']);

    $fabric = Material::create([
        'company_id' => 1, 'code' => 'FAB-'.uniqid(), 'name' => 'Twill', 'type' => 'FABRIC',
        'tracking_level' => 'ROLL', 'gsm' => 200, 'width_cm' => 150,
        'buy_uom_id' => $uomMtr->id, 'use_uom_id' => $uomMtr->id,
    ]);
    $trim = Material::create([
        'company_id' => 1, 'code' => 'TRM-'.uniqid(), 'name' => 'Label', 'type' => 'TRIM',
        'tracking_level' => 'LOT', 'use_uom_id' => $uomPcs->id,
    ]);

    $style = Style::create(['company_id' => 1, 'style_no' => 'ST-'.uniqid(), 'category' => 'WOVEN']);
    $bomSvc = app(BomService::class);
    $bomSvc->markApproved($bomSvc->createVersion($style->id, [
        ['material_id' => $fabric->id, 'qty_per_pcs' => 2.0, 'uom_id' => $uomMtr->id, 'is_backflush' => false],
        ['material_id' => $trim->id, 'qty_per_pcs' => 5, 'uom_id' => $uomPcs->id, 'is_backflush' => true],   // BR-041
    ], $user));

    $op1 = Operation::create(['company_id' => 1, 'code' => 'OP1-'.uniqid(), 'name' => 'Jahit sisi']);
    $op2 = Operation::create(['company_id' => 1, 'code' => 'OP2-'.uniqid(), 'name' => 'Pasang lengan']);
    $routingSvc = app(RoutingService::class);
    $routingSvc->markApproved($routingSvc->createVersion($style->id, [
        ['operation_id' => $op1->id, 'smv' => 6, 'seq' => 1],
        ['operation_id' => $op2->id, 'smv' => 8, 'seq' => 2],
    ], $user));

    $customer = Customer::create(['company_id' => 1, 'code' => 'C-'.uniqid(), 'name' => 'Buyer']);
    $color = Color::create(['company_id' => 1, 'code' => 'BLK'.substr(uniqid(), -2), 'name' => 'Black']);
    $colorway = Colorway::create(['company_id' => 1, 'style_id' => $style->id, 'color_id' => $color->id]);
    $size = Size::create(['company_id' => 1, 'code' => 'M'.substr(uniqid(), -2)]);

    $soSvc = app(SalesOrderService::class);
    $so = $soSvc->create(1, ['customer_id' => $customer->id, 'order_date' => now()->toDateString()], [
        ['style_id' => $style->id, 'colorway_id' => $colorway->id, 'size_id' => $size->id, 'qty' => 100, 'price' => 15],
    ], $user);
    $so->update(['status' => 'APPROVED']);
    $so = $soSvc->confirm($so);

    $warehouse = Warehouse::create(['company_id' => 1, 'code' => 'RM-'.substr(uniqid(), -3), 'name' => 'RM', 'type' => 'RM']);

    // Stok trim 1000 pcs via OPENING
    $its = app(InventoryTransactionService::class);
    $its->post('OPENING', ['company_id' => 1, 'source_document_type' => 'tests', 'source_document_id' => 1],
        [['material_id' => $trim->id, 'warehouse_id' => $warehouse->id, 'qty' => 1000, 'uom_id' => $uomPcs->id, 'unit_cost' => 0.1]], $user);

    // Stok fabric via GR roll-level (R001 300m, R002 200m) → release hold
    $supplier = Supplier::create(['company_id' => 1, 'code' => 'SUP-'.uniqid(), 'name' => 'Tex', 'type' => 'FABRIC']);
    $po = app(PurchasingService::class)->createPo(1, ['supplier_id' => $supplier->id, 'order_date' => now()->toDateString()],
        [['material_id' => $fabric->id, 'qty' => 500, 'uom_id' => $uomMtr->id, 'unit_price' => 10]], $user);
    $po->update(['status' => 'APPROVED']);

    $gr = app(ReceivingService::class)->createAndPost(1, [
        'purchase_order_id' => $po->id, 'warehouse_id' => $warehouse->id, 'received_date' => now()->toDateString(),
    ], [[
        'po_line_id' => $po->lines->first()->id, 'material_id' => $fabric->id,
        'qty_received' => 500, 'uom_id' => $uomMtr->id, 'unit_price' => 10,
        'rolls' => [
            ['roll_no' => 'R001', 'qty_buy' => 300, 'qty_meter_actual' => 300],
            ['roll_no' => 'R002', 'qty_buy' => 200, 'qty_meter_actual' => 200],
        ],
    ]], $user);

    $grLine = $gr->lines->first();
    foreach (FabricRoll::where('gr_line_id', $grLine->id)->get() as $roll) {
        $its->releaseQualityHold(1, [
            'material_id' => $fabric->id, 'warehouse_id' => $warehouse->id,
            'roll_id' => $roll->id, 'uom_id' => $uomMtr->id, 'source_document_id' => $grLine->id,
        ], (float) $roll->qty_buy, $user);
        $roll->update(['status' => 'RELEASED']);
    }

    // MO + release (reservasi: fabric 200 m, trim 500 pcs)
    $moSvc = app(ProductionOrderService::class);
    $mo = $moSvc->release($moSvc->createFromSalesOrder($so, $user)[0], $warehouse->id, $user);

    return [$user, $fabric, $trim, $uomMtr, $uomPcs, $warehouse, $mo, $op1, $op2, $colorway, $size];
}

test('BR-060: issue aktual dari reservasi — reservasi & alokasi ter-update, stok RM turun', function () {
    [$user, $fabric, $trim, $uomMtr, $uomPcs, $warehouse, $mo] = shopFixture();
    $roll = FabricRoll::where('roll_no', 'R001')->firstOrFail();

    $issue = app(MaterialIssueService::class)->issue($mo, $warehouse->id, [[
        'material_id' => $fabric->id, 'qty' => 80, 'uom_id' => $uomMtr->id, 'roll_id' => $roll->id,
    ]], $user);

    expect($issue->mode)->toBe('ACTUAL');
    expect($issue->doc_no)->toStartWith('MI-');

    $res = StockReservation::where('mo_id', $mo->id)->where('material_id', $fabric->id)->firstOrFail();
    expect((float) $res->qty_issued)->toBe(80.0);
    expect($res->status)->toBe('PARTIAL_ISSUED');

    // Saldo fabric: on_hand 500 − 80 = 420; reserved 200 − 80 = 120
    $b = StockBalance::withoutGlobalScopes()->where('material_id', $fabric->id)->where('warehouse_id', $warehouse->id)->whereNull('roll_id')->firstOrFail();
    expect((float) $b->on_hand)->toBe(420.0);
    expect((float) $b->reserved)->toBe(120.0);
});

test('BR-060: issue melebihi sisa reservasi ditolak', function () {
    [$user, $fabric, , $uomMtr, , $warehouse, $mo] = shopFixture();
    $roll = FabricRoll::where('roll_no', 'R001')->firstOrFail();

    app(MaterialIssueService::class)->issue($mo, $warehouse->id, [[
        'material_id' => $fabric->id, 'qty' => 201, 'uom_id' => $uomMtr->id, 'roll_id' => $roll->id,  // reservasi 200
    ]], $user);
})->throws(RuntimeException::class);

test('BR-041: fabric roll-tracked wajib per roll — issue tanpa roll_id ditolak', function () {
    [$user, $fabric, , $uomMtr, , $warehouse, $mo] = shopFixture();

    app(MaterialIssueService::class)->issue($mo, $warehouse->id, [[
        'material_id' => $fabric->id, 'qty' => 50, 'uom_id' => $uomMtr->id,   // tanpa roll_id
    ]], $user);
})->throws(RuntimeException::class);

test('BR-041: backflush trim = BOM × qty_produced persis', function () {
    [$user, , $trim, , $uomPcs, $warehouse, $mo] = shopFixture();

    $mo->update(['qty_produced' => 100]);
    $issue = app(MaterialIssueService::class)->backflush($mo, $warehouse->id, $user);

    expect($issue->mode)->toBe('BACKFLUSH');
    $line = $issue->lines->first();
    expect($line->material_id)->toBe($trim->id);
    expect((float) $line->qty)->toBe(500.0);   // 5 pcs × 100

    $b = StockBalance::withoutGlobalScopes()->where('material_id', $trim->id)->where('warehouse_id', $warehouse->id)->firstOrFail();
    expect((float) $b->on_hand)->toBe(500.0);  // 1000 − 500
});

test('BR-042: leftover roll kembali ke stok available; roll CONSUMED', function () {
    [$user, $fabric, , $uomMtr, , $warehouse, $mo] = shopFixture();
    $roll = FabricRoll::where('roll_no', 'R001')->firstOrFail();   // 300 m tersisa

    // Simulasi pemakaian: konsumsi 220 → sisa 80
    $roll->consume(220);

    $return = app(MaterialIssueService::class)->returnLeftover($mo, $roll->fresh(), $warehouse->id, $user, 'Sisa marker');

    expect((float) $return->qty_returned_meter)->toBe(80.0);
    expect($roll->fresh()->status)->toBe('CONSUMED');
    expect((float) $roll->fresh()->qty_remaining_meter)->toBe(0.0);

    // Stok bertambah kembali via PRODUCTION_RETURN (available — bukan hold)
    $b = StockBalance::withoutGlobalScopes()->where('material_id', $fabric->id)->where('warehouse_id', $warehouse->id)->whereNotNull('roll_id')->first();
    expect($b)->not->toBeNull();
    expect((float) $b->on_hand)->toBe(80.0);
    expect((float) $b->quality_hold)->toBe(0.0);
});
