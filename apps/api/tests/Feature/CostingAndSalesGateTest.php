<?php

use Modules\Core\Models\User;
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

/** Fixture: style dengan BOM & Routing APPROVED + rates */
function approvedStyle(string $suffix = ''): array
{
    $companyId = 1;
    $creator = User::factory()->create(['company_id' => $companyId]);

    $style = Style::create(['company_id' => $companyId, 'style_no' => 'ST-'.$suffix.uniqid(), 'category' => 'WOVEN']);
    $fabric = Material::create(['company_id' => $companyId, 'code' => 'FAB-'.$suffix.uniqid(), 'name' => 'Poplin', 'type' => 'FABRIC']);
    $trim = Material::create(['company_id' => $companyId, 'code' => 'TRM-'.$suffix.uniqid(), 'name' => 'Button', 'type' => 'TRIM']);
    $uom = Uom::create(['company_id' => $companyId, 'code' => 'MTR'.substr(uniqid(), -3), 'name' => 'Meter']);
    $pcs = Uom::create(['company_id' => $companyId, 'code' => 'PCS'.substr(uniqid(), -3), 'name' => 'Pcs']);

    $bomSvc = app(BomService::class);
    $bom = $bomSvc->createVersion($style->id, [
        ['material_id' => $fabric->id, 'qty_per_pcs' => 1.8, 'uom_id' => $uom->id, 'wastage_pct' => 5],   // gross 1.89
        ['material_id' => $trim->id, 'qty_per_pcs' => 5, 'uom_id' => $pcs->id],                          // gross 5
    ], $creator);
    $bomSvc->markApproved($bom);

    $operation = Operation::create(['company_id' => $companyId, 'code' => 'OP-'.$suffix.uniqid(), 'name' => 'Jahit sisi']);
    $routingSvc = app(RoutingService::class);
    $routing = $routingSvc->createVersion($style->id, [
        ['operation_id' => $operation->id, 'smv' => 10],
        ['operation_id' => $operation->id, 'smv' => 5],
    ], $creator);   // total SAM = 15
    $routingSvc->markApproved($routing);

    $line = Line::create(['company_id' => $companyId, 'code' => 'LN-'.$suffix.uniqid(), 'name' => 'Line 1']);
    $period = now()->format('Y-m');
    LineCostRate::create(['company_id' => $companyId, 'line_id' => $line->id, 'period' => $period, 'cost_per_minute' => 0.10]);
    OverheadRate::create(['company_id' => $companyId, 'period' => $period, 'rate_per_minute' => 0.05]);

    return [$style, $fabric, $trim, $line, $period, $creator];
}

test('BR-100: cost sheet FOB = fabric + trim + CM + OH, angka persis', function () {
    [$style, $fabric, $trim, $line, $period, $creator] = approvedStyle('cs1_');

    $sheet = app(CostingService::class)->compute(
        styleId: $style->id,
        companyId: 1,
        materialPrices: [$fabric->id => 6.00, $trim->id => 0.05],
        lineId: $line->id,
        period: $period,
        creator: $creator,
    );

    // Fabric: 1.89 m × $6 = 11.34 ; Trim: 5 × $0.05 = 0.25
    // CM: SAM 15 × $0.10 = 1.50 ; OH: SAM 15 × $0.05 = 0.75
    expect((float) $sheet->fabric_cost)->toBe(11.34);
    expect((float) $sheet->trim_cost)->toBe(0.25);
    expect((float) $sheet->cm_cost)->toBe(1.5);
    expect((float) $sheet->overhead_cost)->toBe(0.75);
    expect($sheet->totalManufacturingCost())->toBe(13.84);

    // BR-100: set FOB dengan margin
    $priced = app(CostingService::class)->setPrice($sheet, 16.0);
    // margin = (16 − 13.84)/13.84 = 15.6069%
    expect((float) $priced->margin_pct)->toBeGreaterThan(15.6)->toBeLessThan(15.61);
});

test('setPrice menolak FOB di bawah total cost', function () {
    [$style, $fabric, $trim, $line, $period, $creator] = approvedStyle('cs2_');

    $sheet = app(CostingService::class)->compute($style->id, 1, [$fabric->id => 6, $trim->id => 0.05], $line->id, $period, $creator);

    app(CostingService::class)->setPrice($sheet, 1.0);
})->throws(RuntimeException::class);

test('BR-023: SO confirm ditolak tanpa BOM approved, lolos setelah approved', function () {
    [$style, $fabric, $trim, $line, $period, $creator] = approvedStyle('so1_');

    $customer = Customer::create(['company_id' => 1, 'code' => 'C-'.uniqid(), 'name' => 'Buyer']);
    $color = Color::create(['company_id' => 1, 'code' => 'BLK', 'name' => 'Black']);
    $colorway = Colorway::create(['company_id' => 1, 'style_id' => $style->id, 'color_id' => $color->id]);
    $size = Size::create(['company_id' => 1, 'code' => 'M']);

    // Style kedua TANPA BOM
    $style2 = Style::create(['company_id' => 1, 'style_no' => 'ST-NOBOM-'.uniqid(), 'category' => 'KNIT']);
    $colorway2 = Colorway::create(['company_id' => 1, 'style_id' => $style2->id, 'color_id' => $color->id]);

    $soSvc = app(SalesOrderService::class);
    $so = $soSvc->create(1, ['customer_id' => $customer->id, 'order_date' => now()->toDateString()], [
        ['style_id' => $style->id, 'colorway_id' => $colorway->id, 'size_id' => $size->id, 'qty' => 1000, 'price' => 16],
        ['style_id' => $style2->id, 'colorway_id' => $colorway2->id, 'size_id' => $size->id, 'qty' => 500, 'price' => 8],
    ], $creator);

    $so->update(['status' => 'APPROVED']);

    // style2 belum punya BOM/routing → confirm harus gagal
    try {
        $soSvc->confirm($so);
        $this->fail('Seharusnya gagal — style2 belum punya BOM/Routing APPROVED');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('BR-023');
    }

    // Sekarang lengkapi style2 → confirm lolos
    $bomSvc = app(BomService::class);
    $bomSvc->markApproved($bomSvc->createVersion($style2->id, [
        ['material_id' => $fabric->id, 'qty_per_pcs' => 1.2, 'uom_id' => $fabric->id ? \Modules\MasterData\Models\Uom::first()->id : 1],
    ], $creator));
    $routingSvc = app(RoutingService::class);
    $routingSvc->markApproved($routingSvc->createVersion($style2->id, [
        ['operation_id' => Operation::first()->id ?? Operation::create(['company_id' => 1, 'code' => 'OPX'.uniqid(), 'name' => 'X'])->id, 'smv' => 8],
    ], $creator));

    $confirmed = $soSvc->confirm($so->fresh());
    expect($confirmed->status)->toBe('CONFIRMED');
});
