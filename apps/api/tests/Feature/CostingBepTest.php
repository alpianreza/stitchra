<?php

use Modules\Core\Models\User;
use Modules\Finance\Services\ActualCostingService;
use Modules\Finance\Services\BepService;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\MasterData\Models\LineCostRate;
use Modules\MasterData\Models\Line;
use Modules\MasterData\Models\Material;
use Modules\MasterData\Models\Operation;
use Modules\MasterData\Models\OverheadRate;
use Modules\MasterData\Models\Style;
use Modules\MasterData\Models\Uom;
use Modules\MasterData\Models\Warehouse;
use Modules\ProductDev\Models\CostSheet;
use Modules\ProductDev\Services\RoutingService;
use Modules\Production\Models\MaterialIssue;
use Modules\Production\Models\ProductionOrder;
use Modules\Sales\Services\SalesOrderService;
use Modules\Subcon\Models\SubconFee;
use Modules\Subcon\Models\SubconOrder;
use Modules\MasterData\Models\Supplier;

test('BR-104: BEP = FC ÷ (harga − variable cost) — angka persis', function () {
    $bep = new BepService();
    $result = $bep->compute(100_000_000, 50_000, 30_000);
    expect($result['bep_qty'])->toBe(5000);
    expect((float) $result['bep_revenue'])->toBe(250_000_000.0);
    expect((float) $result['contribution_margin_per_unit'])->toBe(20000.0);
    $bep->compute(100_000_000, 30_000, 30_000);
})->throws(RuntimeException::class);

test('BR-104: BEP per style dari cost sheet APPROVED', function () {
    $user = User::factory()->create(['company_id' => 1]);
    $style = Style::create(['company_id' => 1, 'style_no' => 'ST-'.uniqid(), 'category' => 'WOVEN']);
    CostSheet::create([
        'company_id' => 1, 'doc_no' => 'COST-TEST-'.uniqid(), 'style_id' => $style->id, 'version' => 1,
        'fabric_cost' => 11.34, 'trim_cost' => 0.25, 'cm_cost' => 1.5, 'overhead_cost' => 0.75,
        'fob_price' => 16, 'status' => 'APPROVED', 'created_by' => $user->id,
    ]);
    $result = app(BepService::class)->forStyle(1, $style->id, 10_000);
    expect($result['bep_qty'])->toBe(4630);
    expect($result['cost_sheet'])->toStartWith('COST-TEST-');
});

test('BR-009/100: actual MO components use valued material, SAM rates, and BR-091 fee while undefined denominator stays null', function () {
    $user = User::factory()->create(['company_id' => 1]);
    $period = now()->format('Y-m');
    $uom = Uom::create(['company_id' => 1, 'code' => 'MTR'.substr(uniqid(), -3), 'name' => 'Meter']);
    $fabric = Material::create(['company_id' => 1, 'code' => 'FAB-'.uniqid(), 'name' => 'Poplin', 'type' => 'FABRIC', 'tracking_level' => 'LOT']);
    $warehouse = Warehouse::create(['company_id' => 1, 'code' => 'RM-'.substr(uniqid(), -3), 'name' => 'RM', 'type' => 'RM']);
    app(InventoryTransactionService::class)->post('OPENING', [
        'company_id' => 1, 'source_document_type' => 'tests', 'source_document_id' => random_int(1000, 999999),
    ], [['material_id' => $fabric->id, 'warehouse_id' => $warehouse->id, 'qty' => 500, 'uom_id' => $uom->id, 'unit_cost' => 10]], $user);

    $style = Style::create(['company_id' => 1, 'style_no' => 'ST-'.uniqid(), 'category' => 'WOVEN']);
    $operation = Operation::create(['company_id' => 1, 'code' => 'OP-'.uniqid(), 'name' => 'Jahit']);
    $routingSvc = app(RoutingService::class);
    $routing = $routingSvc->createVersion($style->id, [['operation_id' => $operation->id, 'smv' => 15]], $user);
    $routingSvc->markApproved($routing);
    $line = Line::create(['company_id' => 1, 'code' => 'LN-'.uniqid(), 'name' => 'Line A']);
    LineCostRate::create(['company_id' => 1, 'line_id' => $line->id, 'period' => $period, 'cost_per_minute' => 0.10]);
    OverheadRate::create(['company_id' => 1, 'period' => $period, 'rate_per_minute' => 0.05]);

    [$so] = erpConfirmedSo($user, $style, 100, 16);
    $mo = ProductionOrder::create([
        'company_id' => 1, 'doc_no' => 'MO-TEST-'.uniqid(), 'sales_order_id' => $so->id,
        'style_id' => $style->id, 'routing_version_id' => $routing->id, 'line_id' => $line->id,
        'qty_planned' => 100, 'qty_produced' => 100, 'status' => 'SEWING', 'created_by' => $user->id,
    ]);
    $issue = MaterialIssue::create([
        'company_id' => 1, 'doc_no' => 'MI-TEST-'.uniqid(), 'production_order_id' => $mo->id,
        'warehouse_id' => $warehouse->id, 'mode' => 'ACTUAL', 'status' => 'POSTED', 'created_by' => $user->id,
    ]);
    $issueLine = $issue->lines()->create(['material_id' => $fabric->id, 'qty' => 190, 'uom_id' => $uom->id]);
    app(InventoryTransactionService::class)->post('MATERIAL_ISSUE', [
        'company_id' => 1, 'source_document_type' => 'material_issues', 'source_document_id' => $issue->id,
    ], [['material_id' => $fabric->id, 'warehouse_id' => $warehouse->id, 'qty' => 190, 'uom_id' => $uom->id, 'unit_cost' => 10, 'source_document_line_id' => $issueLine->id]], $user);

    $subconSupplier = Supplier::create(['company_id' => 1, 'code' => 'SUB-'.uniqid(), 'name' => 'CMT', 'type' => 'SUBCON']);
    $subconOrder = SubconOrder::create([
        'company_id' => 1, 'doc_no' => 'JW-TEST-'.uniqid(), 'supplier_id' => $subconSupplier->id,
        'production_order_id' => $mo->id, 'fee_per_pcs' => 0.3, 'status' => 'RETURNED', 'created_by' => $user->id,
    ]);
    SubconFee::create(['subcon_order_id' => $subconOrder->id, 'return_date' => now()->toDateString(), 'qty_returned' => 100, 'fee_per_pcs' => 0.3, 'total_fee' => 30]);
    CostSheet::create([
        'company_id' => 1, 'doc_no' => 'COST-TEST-'.uniqid(), 'style_id' => $style->id, 'version' => 1,
        'fabric_cost' => 17.5, 'trim_cost' => 0.5, 'cm_cost' => 1.5, 'overhead_cost' => 0.75, 'subcon_cost' => 0.2,
        'fob_price' => 25, 'status' => 'APPROVED', 'created_by' => $user->id,
    ]);

    $result = app(ActualCostingService::class)->computeForMo($mo, $period, 1);
    expect((float) $result['actual']['material'])->toBe(1900.0)
        ->and((float) $result['actual']['labor'])->toBe(150.0)
        ->and((float) $result['actual']['overhead'])->toBe(75.0)
        ->and((float) $result['actual']['subcon'])->toBe(30.0)
        ->and((float) $result['actual']['total'])->toBe(2155.0)
        ->and($result['actual']['per_pcs'])->toBeNull()
        ->and((float) $result['variance_vs_standard']['material'])->toBe(100.0)
        ->and((float) $result['variance_vs_standard']['subcon'])->toBe(10.0)
        ->and($result['variance_vs_standard']['total'])->toBeNull()
        ->and($result['variance_vs_standard']['status'])->toBe('PARTIAL');
});
