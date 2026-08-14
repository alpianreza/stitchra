<?php

use Modules\Core\Models\User;
use Modules\MasterData\Models\Color;
use Modules\MasterData\Models\Colorway;
use Modules\MasterData\Models\Material;
use Modules\MasterData\Models\Style;
use Modules\MasterData\Models\Uom;
use Modules\ProductDev\Services\BomService;

/** BR-030: BOM versioned — approved tidak bisa diedit; approve versi baru → lama OBSOLETE */

function bomFixtures(): array
{
    $style = Style::create(['company_id' => 1, 'style_no' => 'ST-'.uniqid(), 'category' => 'WOVEN']);
    $material = Material::create([
        'company_id' => 1, 'code' => 'FAB-'.uniqid(), 'name' => 'Cotton Poplin', 'type' => 'FABRIC',
    ]);
    $uom = Uom::create(['company_id' => 1, 'code' => 'MTR', 'name' => 'Meter']);
    $creator = User::factory()->create(['company_id' => 1]);

    return [$style, $material, $uom, $creator];
}

test('createVersion membuat versi berurutan', function () {
    [$style, $material, $uom, $creator] = bomFixtures();
    $svc = app(BomService::class);

    $v1 = $svc->createVersion($style->id, [['material_id' => $material->id, 'qty_per_pcs' => 1.8, 'uom_id' => $uom->id]], $creator);
    $v2 = $svc->createVersion($style->id, [['material_id' => $material->id, 'qty_per_pcs' => 1.75, 'uom_id' => $uom->id]], $creator);

    expect($v1->version_no)->toBe(1);
    expect($v2->version_no)->toBe(2);
    expect($v2->status)->toBe('DRAFT');
});

test('versi APPROVED tidak bisa diedit (BR-030)', function () {
    [$style, $material, $uom, $creator] = bomFixtures();
    $svc = app(BomService::class);

    $v1 = $svc->createVersion($style->id, [['material_id' => $material->id, 'qty_per_pcs' => 1.8, 'uom_id' => $uom->id]], $creator);
    $svc->markApproved($v1);

    $svc->updateDraftLines($v1->fresh(), [['material_id' => $material->id, 'qty_per_pcs' => 9.9, 'uom_id' => $uom->id]]);
})->throws(RuntimeException::class);

test('approve versi baru menjadikan versi lama OBSOLETE (BR-030)', function () {
    [$style, $material, $uom, $creator] = bomFixtures();
    $svc = app(BomService::class);

    $v1 = $svc->createVersion($style->id, [['material_id' => $material->id, 'qty_per_pcs' => 1.8, 'uom_id' => $uom->id]], $creator);
    $svc->markApproved($v1);

    $v2 = $svc->createVersion($style->id, [['material_id' => $material->id, 'qty_per_pcs' => 1.7, 'uom_id' => $uom->id]], $creator);
    $svc->markApproved($v2);

    expect($v1->fresh()->status)->toBe('OBSOLETE');
    expect($v2->fresh()->status)->toBe('APPROVED');
    expect($svc->activeVersion($style->id)->id)->toBe($v2->id);
});

test('grossPerPcs memasukkan wastage & shrinkage (BR-031/032)', function () {
    [$style, $material, $uom, $creator] = bomFixtures();
    $svc = app(BomService::class);

    $v = $svc->createVersion($style->id, [[
        'material_id' => $material->id, 'qty_per_pcs' => 1.8, 'uom_id' => $uom->id,
        'wastage_pct' => 5, 'shrinkage_pct' => 3,
    ]], $creator);

    $line = $v->lines->first();
    // 1.8 × 1.05 × 1.03 = 1.94607
    expect($line->grossPerPcs())->toBeGreaterThan(1.946)->toBeLessThan(1.9461);
});
