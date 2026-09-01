<?php

use Modules\Core\Approval\ApprovalEngine;
use Modules\Core\Models\User;
use Modules\Core\Services\NumberingService;
use Modules\MasterData\Models\Color;
use Modules\MasterData\Models\Colorway;
use Modules\MasterData\Models\Customer;
use Modules\MasterData\Models\Line;
use Modules\MasterData\Models\LineCostRate;
use Modules\MasterData\Models\Material;
use Modules\MasterData\Models\Operation;
use Modules\MasterData\Models\OverheadRate;
use Modules\MasterData\Models\Size;
use Modules\MasterData\Models\Style;
use Modules\MasterData\Models\Uom;
use Modules\ProductDev\Services\BomService;
use Modules\ProductDev\Services\CostingService;
use Modules\ProductDev\Services\RoutingService;
use Modules\Sales\Services\SalesOrderService;

function stage3ApprovedFixture(string $suffix): array
{
    $creator = User::factory()->create(['company_id' => 1]);
    $style = Style::create(['company_id' => 1, 'style_no' => 'S3-'.$suffix.uniqid(), 'category' => 'WOVEN']);
    $fabric = Material::create(['company_id' => 1, 'code' => 'F-'.$suffix.uniqid(), 'name' => 'Fabric', 'type' => 'FABRIC', 'tracking_level' => 'ROLL']);
    $trim = Material::create(['company_id' => 1, 'code' => 'T-'.$suffix.uniqid(), 'name' => 'Trim', 'type' => 'TRIM', 'tracking_level' => 'LOT']);
    $uom = Uom::create(['company_id' => 1, 'code' => 'U'.substr(uniqid(), -8), 'name' => 'Unit']);

    $bomService = app(BomService::class);
    $bom = $bomService->createVersion($style->id, [
        ['material_id' => $fabric->id, 'qty_per_pcs' => 2, 'uom_id' => $uom->id],
        ['material_id' => $trim->id, 'qty_per_pcs' => 1, 'uom_id' => $uom->id],
    ], $creator);
    $bomService->markApproved($bom);

    $operation = Operation::create(['company_id' => 1, 'code' => 'OP-'.$suffix.uniqid(), 'name' => 'Sew']);
    $routingService = app(RoutingService::class);
    $routing = $routingService->createVersion($style->id, [
        ['operation_id' => $operation->id, 'smv' => 5],
    ], $creator);
    $routingService->markApproved($routing);

    $line = Line::create(['company_id' => 1, 'code' => 'LN-'.$suffix.uniqid(), 'name' => 'Line']);
    $period = '2026-08';
    LineCostRate::create(['company_id' => 1, 'line_id' => $line->id, 'period' => $period, 'cost_per_minute' => 0.1]);
    OverheadRate::create(['company_id' => 1, 'period' => $period, 'rate_per_minute' => 0.05]);

    return compact('creator', 'style', 'fabric', 'trim', 'uom', 'line', 'period', 'bom');
}

test('BOM submit rollback ke DRAFT bila approval gagal', function () {
    $fixture = stage3ApprovedFixture('rollback-');
    $draft = app(BomService::class)->createVersion($fixture['style']->id, [[
        'material_id' => $fixture['fabric']->id,
        'qty_per_pcs' => 1.9,
        'uom_id' => $fixture['uom']->id,
    ]], $fixture['creator']);

    $approval = Mockery::mock(ApprovalEngine::class);
    $approval->shouldReceive('submit')->once()->andThrow(new RuntimeException('approval unavailable'));
    $service = new BomService($approval);

    expect(fn () => $service->submit($draft, $fixture['creator']))
        ->toThrow(RuntimeException::class, 'approval unavailable');
    expect($draft->fresh()->status)->toBe('DRAFT');
});

test('costing menolak harga material yang tidak lengkap', function () {
    $fixture = stage3ApprovedFixture('price-');

    app(CostingService::class)->compute(
        $fixture['style']->id,
        1,
        [$fixture['fabric']->id => 5],
        $fixture['line']->id,
        $fixture['period'],
        $fixture['creator'],
    );
})->throws(RuntimeException::class, 'harga material');

test('costing menolak rate line atau overhead yang tidak tersedia', function () {
    $fixture = stage3ApprovedFixture('rate-');
    OverheadRate::where('company_id', 1)->where('period', $fixture['period'])->delete();

    app(CostingService::class)->compute(
        $fixture['style']->id,
        1,
        [$fixture['fabric']->id => 5, $fixture['trim']->id => 0.1],
        $fixture['line']->id,
        $fixture['period'],
        $fixture['creator'],
    );
})->throws(RuntimeException::class, 'rate wajib tersedia');

test('SO menolak colorway yang bukan milik style line', function () {
    $fixture = stage3ApprovedFixture('matrix-');
    $otherStyle = Style::create(['company_id' => 1, 'style_no' => 'OTHER-'.uniqid(), 'category' => 'WOVEN']);
    $color = Color::create(['company_id' => 1, 'code' => 'C-'.uniqid(), 'name' => 'Black']);
    $wrongColorway = Colorway::create(['company_id' => 1, 'style_id' => $otherStyle->id, 'color_id' => $color->id]);
    $size = Size::create(['company_id' => 1, 'code' => 'SZ'.substr(uniqid(), -5)]);
    $customer = Customer::create(['company_id' => 1, 'code' => 'CU-'.uniqid(), 'name' => 'Buyer']);

    app(SalesOrderService::class)->create(1, [
        'customer_id' => $customer->id,
        'order_date' => '2026-08-31',
    ], [[
        'style_id' => $fixture['style']->id,
        'colorway_id' => $wrongColorway->id,
        'size_id' => $size->id,
        'qty' => 10,
        'price' => 20,
    ]], $fixture['creator']);
})->throws(RuntimeException::class, 'company/style yang sama');

test('SO submit rollback ke DRAFT bila approval gagal', function () {
    $fixture = stage3ApprovedFixture('so-rollback-');
    $color = Color::create(['company_id' => 1, 'code' => 'C-'.uniqid(), 'name' => 'Navy']);
    $colorway = Colorway::create(['company_id' => 1, 'style_id' => $fixture['style']->id, 'color_id' => $color->id]);
    $size = Size::create(['company_id' => 1, 'code' => 'SZ'.substr(uniqid(), -5)]);
    $customer = Customer::create(['company_id' => 1, 'code' => 'CU-'.uniqid(), 'name' => 'Buyer']);

    $approval = Mockery::mock(ApprovalEngine::class);
    $approval->shouldReceive('submit')->once()->andThrow(new RuntimeException('approval unavailable'));
    $service = new SalesOrderService(
        app(NumberingService::class),
        $approval,
        app(BomService::class),
        app(RoutingService::class),
    );

    $so = $service->create(1, ['customer_id' => $customer->id, 'order_date' => '2026-08-31'], [[
        'style_id' => $fixture['style']->id,
        'colorway_id' => $colorway->id,
        'size_id' => $size->id,
        'qty' => 10,
        'price' => 20,
    ]], $fixture['creator']);

    expect(fn () => $service->submit($so, $fixture['creator']))
        ->toThrow(RuntimeException::class, 'approval unavailable');
    expect($so->fresh()->status)->toBe('DRAFT');
});
