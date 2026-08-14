<?php

use Modules\Core\Models\User;
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
use Modules\Planning\Models\MrpRequirement;
use Modules\Planning\Services\MrpService;
use Modules\ProductDev\Services\BomService;
use Modules\ProductDev\Services\RoutingService;
use Modules\Purchasing\Services\PurchasingService;
use Modules\Sales\Services\SalesOrderService;

/**
 * Fixture Phase 5: style dengan BOM (fabric 1.8/pcs + wastage 5% = 1.89 gross)
 * + routing APPROVED, SO CONFIRMED dengan qty tertentu.
 */
function mrpFixture(float $soQty = 500, float $bomQtyPerPcs = 1.8, float $wastage = 5): array
{
    $user = User::factory()->create(['company_id' => 1]);

    $uom = Uom::create(['company_id' => 1, 'code' => 'MTR'.substr(uniqid(), -3), 'name' => 'Meter']);
    $fabric = Material::create([
        'company_id' => 1, 'code' => 'FAB-'.uniqid(), 'name' => 'Poplin', 'type' => 'FABRIC',
        'tracking_level' => 'LOT', 'safety_stock_qty' => 10,   // BR-043
    ]);

    $style = Style::create(['company_id' => 1, 'style_no' => 'ST-'.uniqid(), 'category' => 'WOVEN']);
    $bomSvc = app(BomService::class);
    $bom = $bomSvc->createVersion($style->id, [
        ['material_id' => $fabric->id, 'qty_per_pcs' => $bomQtyPerPcs, 'uom_id' => $uom->id, 'wastage_pct' => $wastage],
    ], $user);
    $bomSvc->markApproved($bom);

    $operation = Operation::create(['company_id' => 1, 'code' => 'OP-'.uniqid(), 'name' => 'Jahit']);
    $routingSvc = app(RoutingService::class);
    $routingSvc->markApproved($routingSvc->createVersion($style->id, [
        ['operation_id' => $operation->id, 'smv' => 12],
    ], $user));

    $customer = Customer::create(['company_id' => 1, 'code' => 'C-'.uniqid(), 'name' => 'Buyer']);
    $color = Color::create(['company_id' => 1, 'code' => 'BLK'.substr(uniqid(), -2), 'name' => 'Black']);
    $colorway = Colorway::create(['company_id' => 1, 'style_id' => $style->id, 'color_id' => $color->id]);
    $size = Size::create(['company_id' => 1, 'code' => 'M'.substr(uniqid(), -2)]);

    $soSvc = app(SalesOrderService::class);
    $so = $soSvc->create(1, ['customer_id' => $customer->id, 'order_date' => now()->toDateString()], [
        ['style_id' => $style->id, 'colorway_id' => $colorway->id, 'size_id' => $size->id, 'qty' => $soQty, 'price' => 15],
    ], $user);
    $so->update(['status' => 'APPROVED']);
    $so = $soSvc->confirm($so);   // BR-023 gate — lolos karena BOM+Routing approved

    return [$user, $fabric, $uom, $style, $so];
}

test('BR-043: netting MRP persis — net = gross + safety − available − on_order', function () {
    [$user, $fabric, $uom, $style, $so] = mrpFixture(soQty: 500);   // gross = 500 × 1.89 = 945

    // Stok available 100
    $warehouse = Warehouse::create(['company_id' => 1, 'code' => 'RM-'.substr(uniqid(), -3), 'name' => 'RM', 'type' => 'RM']);
    app(InventoryTransactionService::class)->post('OPENING', [
        'company_id' => 1, 'source_document_type' => 'tests', 'source_document_id' => 1,
    ], [['material_id' => $fabric->id, 'warehouse_id' => $warehouse->id, 'qty' => 100, 'uom_id' => $uom->id, 'unit_cost' => 10]], $user);

    // On-order 50 (PO APPROVED belum diterima)
    $supplier = Supplier::create(['company_id' => 1, 'code' => 'SUP-'.uniqid(), 'name' => 'S', 'type' => 'FABRIC']);
    $po = app(PurchasingService::class)->createPo(1, ['supplier_id' => $supplier->id, 'order_date' => now()->toDateString()],
        [['material_id' => $fabric->id, 'qty' => 50, 'uom_id' => $uom->id, 'unit_price' => 10]], $user);
    $po->update(['status' => 'APPROVED']);

    $run = app(MrpService::class)->run(1, ['so_ids' => [$so->id]], $user);

    $req = $run->requirements->firstWhere('material_id', $fabric->id);
    expect((float) $req->gross_qty)->toBe(945.0);        // 500 × 1.8 × 1.05
    expect((float) $req->safety_stock_qty)->toBe(10.0);
    expect((float) $req->available_qty)->toBe(100.0);
    expect((float) $req->on_order_qty)->toBe(50.0);
    expect((float) $req->net_qty)->toBe(795.0);          // 945 + 10 − 100 − 50
});

test('BR-045: MRP read-only — tidak membuat PR/PO otomatis; konversi eksplisit membawa trace (BR-120)', function () {
    [$user, $fabric, $uom, $style, $so] = mrpFixture(soQty: 100);

    $mrp = app(MrpService::class);
    $run = $mrp->run(1, ['so_ids' => [$so->id]], $user);

    // Tidak ada PR otomatis
    expect(\Modules\Purchasing\Models\PurchaseRequest::withoutGlobalScopes()->count())->toBe(0);

    $req = $run->requirements->first();
    $lines = $mrp->toPrLines([$req->id]);

    // Planner membuat PR eksplisit dari saran MRP
    $pr = app(PurchasingService::class)->createPr(1, ['needed_by' => now()->addDays(14)->toDateString()], $lines, 'MRP', $user);
    $mrp->markConverted([$req->id]);

    expect($pr->source)->toBe('MRP');
    expect($pr->lines->first()->mrp_requirement_id)->toBe($req->id);   // BR-120 trace
    expect($req->fresh()->converted_to_pr)->toBeTrue();

    // Requirement yang sudah dikonversi tidak bisa dikonversi ulang
    $mrp->toPrLines([$req->id]);
})->throws(RuntimeException::class);

test('BR-043: gross sudah termasuk wastage & shrinkage dari BOM (BR-031/032)', function () {
    // qty_per_pcs 2.0, wastage 10%, shrinkage 0% → gross per pcs 2.2; SO 100 → 220
    [$user, $fabric, $uom, $style, $so] = mrpFixture(soQty: 100, bomQtyPerPcs: 2.0, wastage: 10);

    $run = app(MrpService::class)->run(1, ['so_ids' => [$so->id]], $user);
    $req = $run->requirements->firstWhere('material_id', $fabric->id);

    expect((float) $req->gross_qty)->toBe(220.0);
});
