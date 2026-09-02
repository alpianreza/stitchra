<?php

use Modules\Core\Models\User;
use Modules\MasterData\Models\Color;
use Modules\MasterData\Models\Colorway;
use Modules\MasterData\Models\Customer;
use Modules\MasterData\Models\Material;
use Modules\MasterData\Models\Operation;
use Modules\MasterData\Models\Size;
use Modules\MasterData\Models\Style;
use Modules\MasterData\Models\Uom;
use Modules\Planning\Models\MrpRequirement;
use Modules\Planning\Models\MrpRun;
use Modules\Planning\Models\MrpTraceLine;
use Modules\Planning\Services\MrpService;
use Modules\ProductDev\Models\Bom;
use Modules\ProductDev\Models\BomLine;
use Modules\ProductDev\Services\BomService;
use Modules\ProductDev\Services\RoutingService;
use Modules\Sales\Services\SalesOrderService;

/**
 * Fixture BR-121: style + BOM APPROVED (fabric 1.8/pcs + wastage 5% = 1.89 gross/pcs)
 * + routing APPROVED + SO CONFIRMED; satu SO line per qty pada $lineQtys (size berbeda).
 */
function mrpTraceFixture(array $lineQtys = [500]): array
{
    $user = User::factory()->create(['company_id' => 1]);

    $uom = Uom::create(['company_id' => 1, 'code' => 'MTR'.substr(uniqid(), -3), 'name' => 'Meter']);
    $fabric = Material::create([
        'company_id' => 1, 'code' => 'FAB-'.uniqid(), 'name' => 'Poplin', 'type' => 'FABRIC',
        'tracking_level' => 'LOT', 'safety_stock_qty' => 0,
    ]);

    $style = Style::create(['company_id' => 1, 'style_no' => 'ST-'.uniqid(), 'category' => 'WOVEN']);
    $bomSvc = app(BomService::class);
    $bom = $bomSvc->createVersion($style->id, [
        ['material_id' => $fabric->id, 'qty_per_pcs' => 1.8, 'uom_id' => $uom->id, 'wastage_pct' => 5],
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

    $lines = [];
    foreach ($lineQtys as $qty) {
        $size = Size::create(['company_id' => 1, 'code' => 'SZ'.substr(uniqid(), -3)]);
        $lines[] = ['style_id' => $style->id, 'colorway_id' => $colorway->id, 'size_id' => $size->id, 'qty' => $qty, 'price' => 15];
    }

    $soSvc = app(SalesOrderService::class);
    $so = $soSvc->create(1, ['customer_id' => $customer->id, 'order_date' => now()->toDateString()], $lines, $user);
    $so->update(['status' => 'APPROVED']);
    $so = $soSvc->confirm($so);   // BR-023 gate — lolos karena BOM+Routing approved

    return [$user, $fabric, $uom, $style, $so];
}

test('BR-121: MRP run menyimpan trace SO line → BOM line → kontribusi gross', function () {
    [$user, $fabric, $uom, $style, $so] = mrpTraceFixture([500]);   // 500 × 1.89 = 945

    $run = app(MrpService::class)->run(1, ['so_ids' => [$so->id]], $user);
    $req = $run->requirements->firstWhere('material_id', $fabric->id);

    $traces = MrpTraceLine::where('mrp_requirement_id', $req->id)->get();
    expect($traces)->toHaveCount(1);
    expect((float) $traces->first()->gross_qty)->toBe(945.0);
    expect($traces->first()->sales_order_line_id)->toBe($so->lines->first()->id);
    expect($traces->first()->bom_line_id)->toBe(BomLine::where('material_id', $fabric->id)->first()->id);

    // Konsistensi dua arah: Σ kontribusi trace == gross requirement
    expect((float) $traces->sum('gross_qty'))->toBe((float) $req->gross_qty);
});

test('BR-121: atribusi per SO line pada SO multi-size (300+200 → 567 + 378 = 945)', function () {
    [$user, $fabric, $uom, $style, $so] = mrpTraceFixture([300, 200]);

    $run = app(MrpService::class)->run(1, ['so_ids' => [$so->id]], $user);
    $req = $run->requirements->firstWhere('material_id', $fabric->id);

    $traces = MrpTraceLine::where('mrp_requirement_id', $req->id)->orderByDesc('gross_qty')->get();
    expect($traces)->toHaveCount(2);
    expect((float) $traces[0]->gross_qty)->toBe(567.0);   // 300 × 1.89
    expect((float) $traces[1]->gross_qty)->toBe(378.0);   // 200 × 1.89
    expect((float) $req->gross_qty)->toBe(945.0);
    expect((float) $traces->sum('gross_qty'))->toBe((float) $req->gross_qty);
    expect($traces->pluck('sales_order_line_id')->sort()->values()->all())
        ->toBe($so->lines->pluck('id')->sort()->values()->all());
});

test('BR-121: run gagal (BOM tidak APPROVED) rollback penuh — tanpa run, requirement, maupun trace', function () {
    [$user, $fabric, $uom, $style, $so] = mrpTraceFixture([100]);

    // Batalkan approval BOM setelah SO confirm → run wajib gagal (BR-023) di tengah transaksi
    Bom::where('style_id', $style->id)->first()->approvedVersion()->update(['status' => 'DRAFT']);

    try {
        app(MrpService::class)->run(1, ['so_ids' => [$so->id]], $user);
        $this->fail('Seharusnya gagal BR-023 (BOM tidak APPROVED).');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('BOM APPROVED');
    }

    expect(MrpRun::withoutGlobalScopes()->count())->toBe(0);
    expect(MrpRequirement::count())->toBe(0);
    expect(MrpTraceLine::count())->toBe(0);
});
