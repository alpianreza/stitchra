<?php

use Modules\Core\Models\User;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\MasterData\Models\Uom;
use Modules\MasterData\Models\Warehouse;
use Modules\Packing\Services\PackingService;
use Modules\ProductDev\Models\CostSheet;
use Modules\Qc\Services\QcService;
use Modules\Reporting\Services\DashboardService;
use Modules\Reporting\Services\ReportService;
use Modules\Shipping\Services\ShipmentService;

test('report order_status: total qty & nilai dari matrix lines', function () {
    $user = User::factory()->create(['company_id' => 1]);
    [$style] = erpApprovedStyle($user);
    [$so] = erpConfirmedSo($user, $style, 100, 15);

    $report = app(ReportService::class)->run(1, 'order_status');

    expect($report['rows'])->toHaveCount(1);
    expect($report['rows'][0]->doc_no)->toBe($so->doc_no);
    expect($report['rows'][0]->status)->toBe('CONFIRMED');
    expect((float) $report['rows'][0]->total_qty)->toBe(100.0);
    expect((float) $report['rows'][0]->total_value)->toBe(1500.0);
});

test('report consumption_variance: variance_pct = (actual − est)/est × 100 (BR-031)', function () {
    $user = User::factory()->create(['company_id' => 1]);
    [$style, $fabric] = erpApprovedStyle($user);

    // Set consumption actual langsung (seolah dari cutting complete)
    \Modules\ProductDev\Models\BomLine::query()->update(['consumption_actual' => 1.9]);

    $report = app(ReportService::class)->run(1, 'consumption_variance');

    expect($report['rows'])->toHaveCount(1);
    expect((float) $report['rows'][0]->qty_per_pcs)->toBe(2.0);
    expect((float) $report['rows'][0]->consumption_actual)->toBe(1.9);
    expect((float) $report['rows'][0]->variance_pct)->toBe(-5.0);   // hemat 5% vs estimasi
});

test('report otd: days_late = ship_date − ex_factory_date', function () {
    [$user, , $style, $so, $mo, $colorway, $size, $fgWh] = packFixture(soQty: 100, tolerance: 5);
    $so->update(['ex_factory_date' => now()->subDays(2)->toDateString()]);

    $qc = app(QcService::class);
    $qc->finalize($qc->create($mo, 'FINAL', 100, $user), $user);
    $packing = app(PackingService::class);
    $pl = $packing->create($so->fresh(), $mo->id, $user);
    $packing->addCarton($pl->fresh(), [], [['style_id' => $style->id, 'colorway_id' => $colorway->id, 'size_id' => $size->id, 'qty' => 100]], $user);
    $pl = $packing->finalize($pl->fresh(), $fgWh->id, $user);

    $shipSvc = app(ShipmentService::class);
    $shipment = $shipSvc->create($pl, ['ship_date' => now()->toDateString()], $user);
    $shipSvc->ship($shipment, $fgWh->id, $user);

    $report = app(ReportService::class)->run(1, 'otd');
    expect($report['rows'])->toHaveCount(1);
    expect((int) $report['rows'][0]->days_late)->toBe(2);   // terlambat 2 hari dari ex-factory
});

test('report bep_position: shipped vs BEP per style (BR-104)', function () {
    [$user, , $style, $so, $mo, $colorway, $size, $fgWh] = packFixture(soQty: 100, tolerance: 5);

    // Cost sheet approved: variable 13.84, FOB 16 → margin 2.16; FC share 100 → BEP = ceil(100/2.16) = 47
    CostSheet::create([
        'company_id' => 1, 'doc_no' => 'COST-TEST-'.uniqid(), 'style_id' => $style->id, 'version' => 1,
        'fabric_cost' => 11.34, 'trim_cost' => 0.25, 'cm_cost' => 1.5, 'overhead_cost' => 0.75,
        'fob_price' => 16, 'status' => 'APPROVED', 'created_by' => $user->id,
    ]);

    // Ship 100
    $qc = app(QcService::class);
    $qc->finalize($qc->create($mo, 'FINAL', 100, $user), $user);
    $packing = app(PackingService::class);
    $pl = $packing->create($so->fresh(), $mo->id, $user);
    $packing->addCarton($pl->fresh(), [], [['style_id' => $style->id, 'colorway_id' => $colorway->id, 'size_id' => $size->id, 'qty' => 100]], $user);
    $pl = $packing->finalize($pl->fresh(), $fgWh->id, $user);
    $shipSvc = app(ShipmentService::class);
    $shipSvc->ship($shipSvc->create($pl, ['ship_date' => now()->toDateString()], $user), $fgWh->id, $user);

    $report = app(ReportService::class)->run(1, 'bep_position', ['fixed_cost_share' => 100]);

    expect($report['rows'])->toHaveCount(1);
    expect($report['rows'][0]->bep_qty)->toBe(47);
    expect((float) $report['rows'][0]->qty_shipped)->toBe(100.0);
    expect($report['rows'][0]->position)->toBe('ABOVE_BEP');
});

test('report tidak dikenal → error jelas', function () {
    app(ReportService::class)->run(1, 'ngasal_report');
})->throws(RuntimeException::class);

test('dashboard KPI cocok dengan data fixture', function () {
    $user = User::factory()->create(['company_id' => 1]);
    [$style, $fabric, $uom] = erpApprovedStyle($user);
    [$so] = erpConfirmedSo($user, $style, 100, 15);

    // Stok 250 @ 10 → nilai 2500
    $warehouse = Warehouse::create(['company_id' => 1, 'code' => 'RM-'.substr(uniqid(), -3), 'name' => 'RM', 'type' => 'RM']);
    app(InventoryTransactionService::class)->post('OPENING', [
        'company_id' => 1, 'source_document_type' => 'tests', 'source_document_id' => 1,
    ], [['material_id' => $fabric->id, 'warehouse_id' => $warehouse->id, 'qty' => 250, 'uom_id' => $uom->id, 'unit_cost' => 10]], $user);

    $kpi = app(DashboardService::class)->kpis(1, $user->id);

    expect($kpi['open_orders']['count'])->toBe(1);
    expect((float) $kpi['open_orders']['value'])->toBe(1500.0);
    expect($kpi['today_output_pcs'])->toBe(0.0);
    expect($kpi['wip_pcs'])->toBe(0.0);
    expect($kpi['pending_my_approvals'])->toBe(0);
    expect($kpi['overdue_deliveries'])->toBe(0);
    expect((float) $kpi['stock_value'])->toBe(2500.0);
});

test('CSV export menghasilkan header + baris', function () {
    $user = User::factory()->create(['company_id' => 1]);
    [$style] = erpApprovedStyle($user);
    erpConfirmedSo($user, $style, 50, 10);

    $svc = app(ReportService::class);
    $csv = $svc->toCsv($svc->run(1, 'order_status'));

    $lines = array_values(array_filter(explode("\n", trim($csv))));
    expect($lines[0])->toContain('doc_no');
    expect($lines)->toHaveCount(2);   // header + 1 baris
});
