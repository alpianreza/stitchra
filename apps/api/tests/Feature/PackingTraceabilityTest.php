<?php

use Modules\Packing\Services\PackingService;
use Modules\Qc\Services\QcService;

it('uses QC FINAL PASS as traceable Packing Input and exposes carton lineage', function () {
    [$user, , $style, $so, $mo, $colorway, $size] = packFixture();
    $qc = app(QcService::class);
    $inspection = $qc->finalize($qc->create($mo, 'FINAL', 100, $user), $user);

    $packing = app(PackingService::class);
    $eligible = collect($packing->eligiblePackingInputs(1))->firstWhere('production_order_id', $mo->id);
    expect($eligible)->not->toBeNull()->and($eligible['remaining_qty'])->toBe(100.0);

    $pl = $packing->create($so, $mo->id, $user);
    $carton = $packing->addCarton($pl, [], [[
        'style_id'=>$style->id, 'colorway_id'=>$colorway->id, 'size_id'=>$size->id, 'qty'=>60,
    ]], $user);

    expect($pl->fresh()->qc_inspection_id)->toBe($inspection->id)
        ->and((float)$carton->lines->sum('qty'))->toBe(60.0);
    $lineage = $packing->lineage($pl->fresh(), $user);
    expect($lineage['packing_input']['id'])->toBe($inspection->id)
        ->and($lineage['cartons'][0]['carton_no'])->toBe($carton->carton_no)
        ->and($lineage['carton_allocation']['authority'])->toBe('NOT_DEFINED');
});

it('rejects carton creation when QC FINAL is pending', function () {
    [$user, , $style, $so, $mo, $colorway, $size] = packFixture();
    app(QcService::class)->create($mo, 'FINAL', 100, $user);
    $packing = app(PackingService::class);
    $pl = $packing->create($so, $mo->id, $user);

    expect(fn() => $packing->addCarton($pl, [], [[
        'style_id'=>$style->id, 'colorway_id'=>$colorway->id, 'size_id'=>$size->id, 'qty'=>10,
    ]], $user))->toThrow(RuntimeException::class, 'BR-080');
});

it('rejects cumulative carton quantity above the QC FINAL PASS lot', function () {
    [$user, , $style, $so, $mo, $colorway, $size] = packFixture(qty: 120);
    $qc = app(QcService::class);
    $qc->finalize($qc->create($mo, 'FINAL', 100, $user), $user);
    $packing = app(PackingService::class);
    $pl = $packing->create($so, $mo->id, $user);
    $packing->addCarton($pl, [], [[
        'style_id'=>$style->id, 'colorway_id'=>$colorway->id, 'size_id'=>$size->id, 'qty'=>70,
    ]], $user);

    expect(fn() => $packing->addCarton($pl->fresh(), [], [[
        'style_id'=>$style->id, 'colorway_id'=>$colorway->id, 'size_id'=>$size->id, 'qty'=>31,
    ]], $user))->toThrow(RuntimeException::class, 'quantity QC FINAL PASS');
});

it('keeps Packing List immutable for carton addition after approval', function () {
    [$user, , $style, $so, $mo, $colorway, $size, $fg] = packFixture();
    $qc = app(QcService::class);
    $qc->finalize($qc->create($mo, 'FINAL', 100, $user), $user);
    $packing = app(PackingService::class);
    $pl = $packing->create($so, $mo->id, $user);
    $packing->addCarton($pl, [], [[
        'style_id'=>$style->id, 'colorway_id'=>$colorway->id, 'size_id'=>$size->id, 'qty'=>100,
    ]], $user);
    $approved = $packing->finalize($pl->fresh(), $fg->id, $user);

    expect(fn() => $packing->addCarton($approved, [], [[
        'style_id'=>$style->id, 'colorway_id'=>$colorway->id, 'size_id'=>$size->id, 'qty'=>1,
    ]], $user))->toThrow(RuntimeException::class, 'DRAFT');
});
