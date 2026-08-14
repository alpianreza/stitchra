<?php

use Modules\Core\Models\User;
use Modules\MasterData\Models\Color;
use Modules\MasterData\Models\Colorway;
use Modules\MasterData\Models\Customer;
use Modules\MasterData\Models\CustomerAqlConfig;
use Modules\MasterData\Models\DefectLibrary;
use Modules\MasterData\Models\Material;
use Modules\MasterData\Models\Operation;
use Modules\MasterData\Models\Size;
use Modules\MasterData\Models\Style;
use Modules\MasterData\Models\Uom;
use Modules\ProductDev\Services\BomService;
use Modules\ProductDev\Services\RoutingService;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Services\ProductionOrderService;
use Modules\Qc\Models\QcInspection;
use Modules\Qc\Services\AqlSamplingService;
use Modules\Qc\Services\QcService;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\SalesOrderService;

test('AQL ISO 2859-1 G-II: lot 1200 → sample 80, AQL 2.5 → Ac 5 / Re 6', function () {
    $aql = new AqlSamplingService();

    $sample = $aql->sampleFor(1200);
    expect($sample['code'])->toBe('J');
    expect($sample['sample_size'])->toBe(80);

    [$ac, $re] = $aql->acceptReject(80, 2.5);
    expect($ac)->toBe(5);
    expect($re)->toBe(6);

    // 4 major < Re 6 → PASS ; 6 major ≥ Re 6 → FAIL
    expect($aql->verdict(1200, defectsMajor: 4, defectsMinor: 3, defectsCritical: 0, aqlMajor: 2.5, aqlMinor: 4.0)['verdict'])->toBe('PASS');
    expect($aql->verdict(1200, defectsMajor: 6, defectsMinor: 0, defectsCritical: 0, aqlMajor: 2.5, aqlMinor: 4.0)['verdict'])->toBe('FAIL');

    // Critical: Ac selalu 0 — satu saja critical = FAIL (BR-008)
    expect($aql->verdict(1200, defectsMajor: 0, defectsMinor: 0, defectsCritical: 1, aqlMajor: 2.5, aqlMinor: 4.0)['verdict'])->toBe('FAIL');
});

/** Fixture: SO CONFIRMED + MO + customer dengan AQL config */
function qcFixture(): array
{
    $user = User::factory()->create(['company_id' => 1]);
    $uom = Uom::create(['company_id' => 1, 'code' => 'MTR'.substr(uniqid(), -3), 'name' => 'Meter']);
    $fabric = Material::create(['company_id' => 1, 'code' => 'FAB-'.uniqid(), 'name' => 'Poplin', 'type' => 'FABRIC', 'tracking_level' => 'LOT']);

    $customer = Customer::create(['company_id' => 1, 'code' => 'C-'.uniqid(), 'name' => 'Buyer A', 'shipment_tolerance_pct' => 5]);
    CustomerAqlConfig::create([
        'company_id' => 1, 'customer_id' => $customer->id,
        'inspection_level' => 'G2', 'aql_major' => 2.5, 'aql_minor' => 4.0, 'aql_critical' => 0,
    ]);

    $style = Style::create(['company_id' => 1, 'style_no' => 'ST-'.uniqid(), 'category' => 'WOVEN', 'customer_id' => $customer->id]);
    $bomSvc = app(BomService::class);
    $bomSvc->markApproved($bomSvc->createVersion($style->id, [
        ['material_id' => $fabric->id, 'qty_per_pcs' => 2.0, 'uom_id' => $uom->id],
    ], $user));
    $operation = Operation::create(['company_id' => 1, 'code' => 'OP-'.uniqid(), 'name' => 'Jahit']);
    $routingSvc = app(RoutingService::class);
    $routingSvc->markApproved($routingSvc->createVersion($style->id, [['operation_id' => $operation->id, 'smv' => 10]], $user));

    $color = Color::create(['company_id' => 1, 'code' => 'BLK'.substr(uniqid(), -2), 'name' => 'Black']);
    $colorway = Colorway::create(['company_id' => 1, 'style_id' => $style->id, 'color_id' => $color->id]);
    $size = Size::create(['company_id' => 1, 'code' => 'M'.substr(uniqid(), -2)]);

    $soSvc = app(SalesOrderService::class);
    $so = $soSvc->create(1, ['customer_id' => $customer->id, 'order_date' => now()->toDateString()], [
        ['style_id' => $style->id, 'colorway_id' => $colorway->id, 'size_id' => $size->id, 'qty' => 1200, 'price' => 15],
    ], $user);
    $so->update(['status' => 'APPROVED']);
    $so = $soSvc->confirm($so);

    $mo = app(ProductionOrderService::class)->createFromSalesOrder($so, $user)[0];

    return [$user, $customer, $style, $so, $mo, $colorway, $size];
}

test('BR-008: inspeksi FINAL snapshot AQL dari config buyer + sample size otomatis', function () {
    [$user, $customer, , , $mo] = qcFixture();

    $insp = app(QcService::class)->create($mo, 'FINAL', 1200, $user);

    expect($insp->customer_id)->toBe($customer->id);
    expect((float) $insp->aql_major)->toBe(2.5);          // snapshot dari customer_aql_configs
    expect((float) $insp->aql_minor)->toBe(4.0);
    expect($insp->sample_size)->toBe(80);                  // lot 1200 → J → 80
    expect($insp->accept_major)->toBe(5);
    expect($insp->reject_major)->toBe(6);
    expect($insp->cycle)->toBe(1);
});

test('BR-071/072/073: defect dari library → verdict AQL otomatis; FAIL → REWORK cycle naik', function () {
    [$user, , , , $mo] = qcFixture();
    $qc = app(QcService::class);

    $majorDefect = DefectLibrary::create(['company_id' => 1, 'code' => 'D-'.uniqid(), 'name' => 'Jahitan loncat', 'category' => 'WORKMANSHIP', 'severity' => 'MAJOR']);
    $minorDefect = DefectLibrary::create(['company_id' => 1, 'code' => 'D-'.uniqid(), 'name' => 'Benang sisa', 'category' => 'WORKMANSHIP', 'severity' => 'MINOR']);

    // Cycle 1: 6 major ≥ Re 6 → FAIL → REWORK
    $insp = $qc->create($mo, 'FINAL', 1200, $user);
    $qc->recordDefects($insp, [
        ['defect_id' => $majorDefect->id, 'qty' => 6],
        ['defect_id' => $minorDefect->id, 'qty' => 3],
    ], $user);

    $result = $qc->finalize($insp->fresh(), $user);
    expect($result->verdict)->toBe('REWORK');              // BR-073: FAIL masuk loop rework
    expect($result->defects_major)->toBe(6);

    // Inspeksi ber-verdict tidak bisa ditambah defect
    $qc->recordDefects($result, [['defect_id' => $minorDefect->id, 'qty' => 1]], $user);
})->throws(RuntimeException::class);

test('BR-073: rework loop — inspeksi ulang cycle 2 PASS setelah perbaikan', function () {
    [$user, , , , $mo] = qcFixture();
    $qc = app(QcService::class);

    $majorDefect = DefectLibrary::create(['company_id' => 1, 'code' => 'D-'.uniqid(), 'name' => 'Jahitan loncat', 'category' => 'WORKMANSHIP', 'severity' => 'MAJOR']);

    // Cycle 1 FAIL
    $c1 = $qc->create($mo, 'FINAL', 1200, $user);
    $qc->recordDefects($c1, [['defect_id' => $majorDefect->id, 'qty' => 8]], $user);
    expect($qc->finalize($c1->fresh(), $user)->verdict)->toBe('REWORK');

    // Cycle 2: setelah rework, 2 major < Re 6 → PASS
    $c2 = $qc->create($mo, 'FINAL', 1200, $user);
    expect($c2->cycle)->toBe(2);
    $qc->recordDefects($c2, [['defect_id' => $majorDefect->id, 'qty' => 2]], $user);
    expect($qc->finalize($c2->fresh(), $user)->verdict)->toBe('PASS');
});
