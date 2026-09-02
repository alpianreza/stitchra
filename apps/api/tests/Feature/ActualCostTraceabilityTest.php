<?php

use Modules\Core\Models\User;
use Modules\Finance\Services\ActualCostingService;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\MasterData\Models\Line;
use Modules\MasterData\Models\LineCostRate;
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
use Modules\Subcon\Models\SubconFee;
use Modules\Subcon\Models\SubconOrder;
use Modules\MasterData\Models\Supplier;

function actualCostTraceFixture(): array
{
    $user = User::factory()->create(['company_id' => 1]);
    $period = now()->format('Y-m');
    $uom = Uom::create(['company_id' => 1, 'code' => 'MTR'.substr(uniqid(), -3), 'name' => 'Meter']);
    $material = Material::create(['company_id' => 1, 'code' => 'FAB-'.uniqid(), 'name' => 'Fabric', 'type' => 'FABRIC', 'tracking_level' => 'LOT']);
    $warehouse = Warehouse::create(['company_id' => 1, 'code' => 'RM-'.substr(uniqid(), -3), 'name' => 'RM', 'type' => 'RM']);
    app(InventoryTransactionService::class)->post('OPENING', [
        'company_id' => 1, 'source_document_type' => 'cost_trace_tests', 'source_document_id' => random_int(10000, 999999),
    ], [['material_id' => $material->id, 'warehouse_id' => $warehouse->id, 'qty' => 100, 'uom_id' => $uom->id, 'unit_cost' => 10]], $user);

    $style = Style::create(['company_id' => 1, 'style_no' => 'ST-'.uniqid(), 'category' => 'WOVEN']);
    $operation = Operation::create(['company_id' => 1, 'code' => 'OP-'.uniqid(), 'name' => 'Final']);
    $routing = app(RoutingService::class)->createVersion($style->id, [['operation_id' => $operation->id, 'smv' => 5]], $user);
    app(RoutingService::class)->markApproved($routing);
    $line = Line::create(['company_id' => 1, 'code' => 'LN-'.uniqid(), 'name' => 'Line']);
    LineCostRate::create(['company_id' => 1, 'line_id' => $line->id, 'period' => $period, 'cost_per_minute' => 0.1]);
    OverheadRate::create(['company_id' => 1, 'period' => $period, 'rate_per_minute' => 0.05]);
    [$so] = erpConfirmedSo($user, $style, 10, 20);
    $mo = ProductionOrder::create([
        'company_id' => 1, 'doc_no' => 'MO-COST-'.uniqid(), 'sales_order_id' => $so->id,
        'style_id' => $style->id, 'routing_version_id' => $routing->id, 'line_id' => $line->id,
        'qty_planned' => 10, 'qty_produced' => 10, 'status' => 'SEWING', 'created_by' => $user->id,
    ]);
    CostSheet::create([
        'company_id' => 1, 'doc_no' => 'COST-'.uniqid(), 'style_id' => $style->id, 'version' => 1,
        'fabric_cost' => 10, 'trim_cost' => 0, 'cm_cost' => 0.5, 'overhead_cost' => 0.25,
        'subcon_cost' => 0.2, 'other_cost' => 0, 'fob_price' => 20, 'status' => 'APPROVED', 'created_by' => $user->id,
    ]);
    $issue = MaterialIssue::create([
        'company_id' => 1, 'doc_no' => 'MI-COST-'.uniqid(), 'production_order_id' => $mo->id,
        'warehouse_id' => $warehouse->id, 'mode' => 'ACTUAL', 'status' => 'POSTED', 'created_by' => $user->id,
    ]);
    $issueLine = $issue->lines()->create(['material_id' => $material->id, 'qty' => 10, 'uom_id' => $uom->id]);
    app(InventoryTransactionService::class)->post('MATERIAL_ISSUE', [
        'company_id' => 1, 'source_document_type' => 'material_issues', 'source_document_id' => $issue->id,
    ], [['material_id' => $material->id, 'warehouse_id' => $warehouse->id, 'qty' => 10, 'uom_id' => $uom->id, 'unit_cost' => 10, 'source_document_line_id' => $issueLine->id]], $user);

    $vendor = Supplier::create(['company_id' => 1, 'code' => 'SUB-'.uniqid(), 'name' => 'Vendor', 'type' => 'SUBCON']);
    $job = SubconOrder::create([
        'company_id' => 1, 'doc_no' => 'JW-COST-'.uniqid(), 'supplier_id' => $vendor->id,
        'production_order_id' => $mo->id, 'fee_per_pcs' => 0.2, 'status' => 'RETURNED', 'created_by' => $user->id,
    ]);
    SubconFee::create(['subcon_order_id' => $job->id, 'return_date' => now()->toDateString(), 'qty_returned' => 10, 'fee_per_pcs' => 0.2, 'total_fee' => 2]);

    return [$user, $period, $mo, $issue, $job];
}

test('actual cost reverse traces valued material and BR-091 subcon sources without a parallel ledger', function () {
    [$user, $period, $mo, $issue, $job] = actualCostTraceFixture();
    $result = app(ActualCostingService::class)->computeForMo($mo, $period, 1);

    expect($result['calculation']['mode'])->toBe('COMPUTED_READ_ONLY')
        ->and($result['calculation']['persisted'])->toBeFalse()
        ->and($result['components']['material']['issues'][0]->issue_id)->toBe($issue->id)
        ->and((float) $result['components']['material']['issues'][0]->total_cost)->toBe(100.0)
        ->and($result['components']['subcon']['fees'][0]->job_work_id)->toBe($job->id)
        ->and((float) $result['actual']['subcon'])->toBe(2.0)
        ->and($result['actual']['per_pcs'])->toBeNull()
        ->and($result['authorities']['cost_per_unit_denominator'])->toBe('NOT_DEFINED')
        ->and($result['components']['production']['output']['authority'])->toBe('LEGACY_QTY_PRODUCED_WRITER_NOT_DEFINED')
        ->and($result['variance_vs_standard']['status'])->toBe('PARTIAL');
});

test('actual cost rejects cross-company invocation and keeps undefined authorities explicit', function () {
    [, $period, $mo] = actualCostTraceFixture();
    expect(fn () => app(ActualCostingService::class)->computeForMo($mo, $period, 2))
        ->toThrow(RuntimeException::class, 'company aktif');
});
