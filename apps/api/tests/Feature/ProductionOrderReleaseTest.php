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
use Modules\MasterData\Models\Uom;
use Modules\MasterData\Models\Warehouse;
use Modules\ProductDev\Services\BomService;
use Modules\ProductDev\Services\RoutingService;
use Modules\Production\Services\ProductionOrderService;
use Modules\Sales\Services\SalesOrderService;

/** Fixture: SO CONFIRMED + BOM/Routing APPROVED + stok terkontrol */
function moFixture(float $stockQty, float $qtyPerPcs = 2.0, float $soQty = 100): array
{
    $user = User::factory()->create(['company_id' => 1]);
    $uom = Uom::create(['company_id' => 1, 'code' => 'MTR'.substr(uniqid(), -3), 'name' => 'Meter']);
    $fabric = Material::create(['company_id' => 1, 'code' => 'FAB-'.uniqid(), 'name' => 'Twill', 'type' => 'FABRIC', 'tracking_level' => 'LOT']);

    $style = Style::create(['company_id' => 1, 'style_no' => 'ST-'.uniqid(), 'category' => 'WOVEN']);
    $bomSvc = app(BomService::class);
    $bomSvc->markApproved($bomSvc->createVersion($style->id, [
        ['material_id' => $fabric->id, 'qty_per_pcs' => $qtyPerPcs, 'uom_id' => $uom->id],
    ], $user));

    $operation = Operation::create(['company_id' => 1, 'code' => 'OP-'.uniqid(), 'name' => 'Jahit']);
    $routingSvc = app(RoutingService::class);
    $routingSvc->markApproved($routingSvc->createVersion($style->id, [
        ['operation_id' => $operation->id, 'smv' => 10],
    ], $user));

    $customer = Customer::create(['company_id' => 1, 'code' => 'C-'.uniqid(), 'name' => 'Buyer']);
    $color = Color::create(['company_id' => 1, 'code' => 'NVY'.substr(uniqid(), -2), 'name' => 'Navy']);
    $colorway = Colorway::create(['company_id' => 1, 'style_id' => $style->id, 'color_id' => $color->id]);
    $size = Size::create(['company_id' => 1, 'code' => 'L'.substr(uniqid(), -2)]);

    $soSvc = app(SalesOrderService::class);
    $so = $soSvc->create(1, ['customer_id' => $customer->id, 'order_date' => now()->toDateString()], [
        ['style_id' => $style->id, 'colorway_id' => $colorway->id, 'size_id' => $size->id, 'qty' => $soQty, 'price' => 12],
    ], $user);
    $so->update(['status' => 'APPROVED']);
    $so = $soSvc->confirm($so);

    $warehouse = Warehouse::create(['company_id' => 1, 'code' => 'RM-'.substr(uniqid(), -3), 'name' => 'RM', 'type' => 'RM']);
    if ($stockQty > 0) {
        app(InventoryTransactionService::class)->post('OPENING', [
            'company_id' => 1, 'source_document_type' => 'tests', 'source_document_id' => 1,
        ], [['material_id' => $fabric->id, 'warehouse_id' => $warehouse->id, 'qty' => $stockQty, 'uom_id' => $uom->id, 'unit_cost' => 10]], $user);
    }

    return [$user, $fabric, $uom, $style, $so, $warehouse];
}

test('createFromSalesOrder membuat MO per style dengan snapshot BOM/Routing (BR-030)', function () {
    [$user, $fabric, $uom, $style, $so, $warehouse] = moFixture(stockQty: 0, soQty: 250);

    $mos = app(ProductionOrderService::class)->createFromSalesOrder($so, $user);

    expect($mos)->toHaveCount(1);
    $mo = $mos[0];
    expect($mo->status)->toBe('PLANNED');
    expect((float) $mo->qty_planned)->toBe(250.0);
    expect($mo->bom_version_id)->not->toBeNull();        // snapshot versi — perubahan BOM baru tidak mengubah MO
    expect($mo->routing_version_id)->not->toBeNull();
    expect($mo->doc_no)->toStartWith('MO-');

    // Duplikasi dicegah
    app(ProductionOrderService::class)->createFromSalesOrder($so->fresh(), $user);
})->throws(RuntimeException::class);

test('BR-060: release MO = hard reservation — reserved naik, status RELEASED', function () {
    // Stok 500, kebutuhan = 100 pcs × 2.0 = 200
    [$user, $fabric, $uom, $style, $so, $warehouse] = moFixture(stockQty: 500);

    $svc = app(ProductionOrderService::class);
    $mo = $svc->createFromSalesOrder($so, $user)[0];

    $released = $svc->release($mo, $warehouse->id, $user);

    expect($released->status)->toBe('RELEASED');

    $b = StockBalance::withoutGlobalScopes()->where('material_id', $fabric->id)->where('warehouse_id', $warehouse->id)->firstOrFail();
    expect((float) $b->reserved)->toBe(200.0);
    expect($b->available())->toBe(300.0);   // 500 − 200 reserved

    $res = StockReservation::where('mo_id', $mo->id)->firstOrFail();
    expect((float) $res->qty_reserved)->toBe(200.0);
    expect($res->status)->toBe('ACTIVE');

    $alloc = $released->materialAllocations->first();
    expect((float) $alloc->qty_required)->toBe(200.0);
    expect((float) $alloc->qty_reserved)->toBe(200.0);
});

test('BR-040/060: release dengan shortage DITOLAK atomic — tidak ada reservasi parsial', function () {
    // Stok 150 < kebutuhan 200
    [$user, $fabric, $uom, $style, $so, $warehouse] = moFixture(stockQty: 150);

    $svc = app(ProductionOrderService::class);
    $mo = $svc->createFromSalesOrder($so, $user)[0];

    try {
        $svc->release($mo, $warehouse->id, $user);
        $this->fail('Seharusnya RuntimeException (shortage)');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('BR-040');
        expect($e->getMessage())->toContain('kurang 50');
    }

    // Atomic: tidak ada reservasi & saldo tidak berubah
    expect(StockReservation::where('mo_id', $mo->id)->count())->toBe(0);
    $b = StockBalance::withoutGlobalScopes()->where('material_id', $fabric->id)->where('warehouse_id', $warehouse->id)->firstOrFail();
    expect((float) $b->reserved)->toBe(0.0);
    expect($b->available())->toBe(150.0);
    expect($mo->fresh()->status)->toBe('PLANNED');
});

test('unrelease melepas reservasi dan mengembalikan available', function () {
    [$user, $fabric, $uom, $style, $so, $warehouse] = moFixture(stockQty: 500);

    $svc = app(ProductionOrderService::class);
    $mo = $svc->release($svc->createFromSalesOrder($so, $user)[0], $warehouse->id, $user);

    $back = $svc->unrelease($mo, $user);

    expect($back->status)->toBe('PLANNED');
    $b = StockBalance::withoutGlobalScopes()->where('material_id', $fabric->id)->where('warehouse_id', $warehouse->id)->firstOrFail();
    expect((float) $b->reserved)->toBe(0.0);
    expect($b->available())->toBe(500.0);
    expect(StockReservation::where('mo_id', $mo->id)->first()->status)->toBe('RELEASED');
});
