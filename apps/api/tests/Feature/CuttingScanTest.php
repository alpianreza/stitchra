<?php

use Modules\Core\Models\Company;
use Modules\Core\Support\CurrentCompany;
use Modules\Cutting\Services\CuttingService;
use Modules\Production\Services\MaterialIssueService;
use Modules\Receiving\Models\FabricRoll;
use Modules\ShopFloor\Models\ProductionScan;
use Modules\ShopFloor\Services\ScanService;

function createCutWithBundles(array $fixture, float $qty = 10): array
{
    [$user, , , , , , $mo, , , $colorway, $size] = $fixture;
    $service = app(CuttingService::class);
    $cut = $service->create($mo, [['colorway_id' => $colorway->id, 'size_id' => $size->id, 'qty_cut' => $qty]], $user);
    $bundles = $service->generateBundles($cut, $cut->lines->first()->id, 10, $user);
    return [$cut, $bundles];
}

test('cut order mengubah MO RELEASED ke CUTTING dan menolak over-cut matrix SO', function () {
    $fixture = shopFixture();
    [$user, , , , , , $mo, , , $colorway, $size] = $fixture;
    $service = app(CuttingService::class);
    $cut = $service->create($mo, [['colorway_id' => $colorway->id, 'size_id' => $size->id, 'qty_cut' => 100]], $user);

    expect($cut->status)->toBe('IN_PROGRESS')
        ->and($mo->fresh()->status)->toBe('CUTTING')
        ->and($mo->fresh()->actual_start)->not->toBeNull();

    expect(fn () => $service->create($mo->fresh(), [[
        'colorway_id' => $colorway->id, 'size_id' => $size->id, 'qty_cut' => 1,
    ]], $user))->toThrow(RuntimeException::class, 'melebihi qty order');
});

test('marker wajib didukung issue roll dan complete menyimpan actual consumption pada MO allocation', function () {
    $fixture = shopFixture();
    [$user, $fabric, , $uom, , $warehouse, $mo, , , $colorway, $size] = $fixture;
    $roll = FabricRoll::where('roll_no', 'R001')->firstOrFail();
    $cutting = app(CuttingService::class);
    $cut = $cutting->create($mo, [['colorway_id' => $colorway->id, 'size_id' => $size->id, 'qty_cut' => 100]], $user);

    expect(fn () => $cutting->recordMarker($cut, [[
        'roll_id' => $roll->id, 'marker_length_m' => 9.5, 'plies' => 20,
        'qty_fabric_used_m' => 190, 'efficiency_pct' => 92,
    ]], $user))->toThrow(RuntimeException::class, 'sudah di-issue');

    app(MaterialIssueService::class)->issue($mo->fresh(), $warehouse->id, [[
        'material_id' => $fabric->id, 'qty' => 190, 'uom_id' => $uom->id, 'roll_id' => $roll->id,
    ]], $user);
    $cutting->recordMarker($cut, [[
        'roll_id' => $roll->id, 'marker_length_m' => 9.5, 'plies' => 20,
        'qty_fabric_used_m' => 190, 'efficiency_pct' => 92,
    ]], $user);
    $cutting->generateBundles($cut, $cut->lines->first()->id, 10, $user);
    $cutting->complete($cut->fresh(), $user);

    $allocation = $mo->materialAllocations()->where('material_id', $fabric->id)->firstOrFail();
    $bomLine = $mo->bomVersion->lines()->where('material_id', $fabric->id)->firstOrFail();
    expect((float) $roll->fresh()->qty_remaining_meter)->toBe(110.0)
        ->and((float) $allocation->qty_consumed)->toBe(190.0)
        ->and((float) $allocation->actual_consumption_per_pcs)->toBe(1.9)
        ->and($bomLine->consumption_actual)->toBeNull();
});

test('bundle generation exact dan tidak dapat diulang', function () {
    $fixture = shopFixture();
    [$user] = $fixture;
    [$cut, $bundles] = createCutWithBundles($fixture, 25);
    expect($bundles)->toHaveCount(3)
        ->and((float) $bundles[0]->qty)->toBe(10.0)
        ->and((float) $bundles[2]->qty)->toBe(5.0)
        ->and(collect($bundles)->pluck('bundle_no')->unique())->toHaveCount(3);
    expect(fn () => app(CuttingService::class)->generateBundles($cut, $cut->lines->first()->id, 10, $user))
        ->toThrow(RuntimeException::class, 'sudah digenerate');
});

test('scan mengunci urutan routing dan menolak duplicate direction', function () {
    $fixture = shopFixture();
    [$user, , , , , , $mo, $op1, $op2] = $fixture;
    [, $bundles] = createCutWithBundles($fixture);
    $bundle = $bundles[0];
    $scan = app(ScanService::class);

    expect(fn () => $scan->scan(1, $bundle->bundle_no, ['operation_id'=>$op1->id,'direction'=>'OUT','stage'=>'SEWING'], $user))
        ->toThrow(RuntimeException::class, 'OUT tanpa IN');
    expect(fn () => $scan->scan(1, $bundle->bundle_no, ['operation_id'=>$op2->id,'direction'=>'IN','stage'=>'SEWING'], $user))
        ->toThrow(RuntimeException::class, 'operasi sebelumnya');

    $scan->scan(1, $bundle->bundle_no, ['operation_id'=>$op1->id,'direction'=>'IN','stage'=>'SEWING'], $user);
    expect(fn () => $scan->scan(1, $bundle->bundle_no, ['operation_id'=>$op1->id,'direction'=>'IN','stage'=>'SEWING'], $user))
        ->toThrow(RuntimeException::class, 'duplicate IN');
    $scan->scan(1, $bundle->bundle_no, ['operation_id'=>$op1->id,'direction'=>'OUT','stage'=>'SEWING'], $user);
    $scan->scan(1, $bundle->bundle_no, ['operation_id'=>$op2->id,'direction'=>'IN','stage'=>'SEWING'], $user);

    expect($mo->fresh()->status)->toBe('SEWING')
        ->and($bundle->fresh()->current_stage)->toBe('SEWING')
        ->and(ProductionScan::where('bundle_id', $bundle->id)->count())->toBe(3);
});

test('finishing ditolak sebelum seluruh routing operation OUT', function () {
    $fixture = shopFixture();
    [$user, , , , , , , $op1] = $fixture;
    [, $bundles] = createCutWithBundles($fixture);
    expect(fn () => app(ScanService::class)->scan(1, $bundles[0]->bundle_no, [
        'operation_id'=>$op1->id,'direction'=>'IN','stage'=>'FINISHING',
    ], $user))->toThrow(RuntimeException::class, 'seluruh operasi sewing');
});

test('WIP dan daily output dibatasi company', function () {
    $fixture = shopFixture();
    [$user, , , , , , $mo, $op1] = $fixture;
    [, $bundles] = createCutWithBundles($fixture, 20);
    $scan = app(ScanService::class);
    $scan->scan(1, $bundles[0]->bundle_no, ['operation_id'=>$op1->id,'direction'=>'IN','stage'=>'SEWING'], $user);
    $scan->scan(1, $bundles[0]->bundle_no, ['operation_id'=>$op1->id,'direction'=>'OUT','stage'=>'SEWING'], $user);

    $wip = $scan->wipByStage(1, $mo->id);
    expect($wip['SEWING']['pcs'])->toBe(10.0);

    $other = Company::factory()->create();
    CurrentCompany::set($other->id);
    expect(fn () => $scan->wipByStage($other->id, $mo->id))->toThrow(RuntimeException::class);
});

test('production scan append-only', function () {
    $fixture = shopFixture();
    [$user, , , , , , , $op1] = $fixture;
    [, $bundles] = createCutWithBundles($fixture);
    $event = app(ScanService::class)->scan(1, $bundles[0]->bundle_no, [
        'operation_id'=>$op1->id,'direction'=>'IN','stage'=>'SEWING',
    ], $user);
    $event->update(['direction' => 'OUT']);
})->throws(LogicException::class, 'append-only');
