<?php

use Modules\Cutting\Services\CuttingService;
use Modules\MasterData\Models\DefectLibrary;
use Modules\ShopFloor\Services\ReworkService;
use Modules\ShopFloor\Services\ScanService;

it('mencatat defect library, menahan bundle, dan mengaktifkan kembali setelah resolve', function () {
    [$user, , , , , , $mo, $op1, , $colorway, $size] = shopFixture();
    $cutting = app(CuttingService::class);
    $cut = $cutting->create($mo, [['colorway_id'=>$colorway->id,'size_id'=>$size->id,'qty_cut'=>10]], $user);
    $bundle = $cutting->generateBundles($cut, $cut->lines->first()->id, 10, $user)[0];
    $defect = DefectLibrary::create([
        'company_id'=>1,'code'=>'RW-'.uniqid(),'name'=>'Open seam',
        'category'=>'WORKMANSHIP','severity'=>'MAJOR','is_active'=>true,
    ]);

    $rework = app(ReworkService::class)->record(1, $bundle->bundle_no, [
        'operation_id'=>$op1->id,'defect_id'=>$defect->id,'qty'=>2,'notes'=>'Repair seam',
    ], $user);
    expect($bundle->fresh()->status)->toBe('REWORK')
        ->and((float) $rework->qty)->toBe(2.0)
        ->and($rework->resolved_at)->toBeNull();

    expect(fn () => app(ScanService::class)->scan(1, $bundle->bundle_no, [
        'operation_id'=>$op1->id,'direction'=>'IN','stage'=>'SEWING',
    ], $user))->toThrow(RuntimeException::class, 'Bundle aktif tidak ditemukan');

    $resolved = app(ReworkService::class)->resolve($rework, $user);
    expect($resolved->resolved_at)->not->toBeNull()
        ->and($bundle->fresh()->status)->toBe('ACTIVE');
});

it('menolak qty rework melebihi qty bundle dan resolve berulang', function () {
    [$user, , , , , , $mo, $op1, , $colorway, $size] = shopFixture();
    $cutting = app(CuttingService::class);
    $cut = $cutting->create($mo, [['colorway_id'=>$colorway->id,'size_id'=>$size->id,'qty_cut'=>10]], $user);
    $bundle = $cutting->generateBundles($cut, $cut->lines->first()->id, 10, $user)[0];
    $defect = DefectLibrary::create([
        'company_id'=>1,'code'=>'RW-'.uniqid(),'name'=>'Broken stitch',
        'category'=>'WORKMANSHIP','severity'=>'MINOR','is_active'=>true,
    ]);
    $service = app(ReworkService::class);

    expect(fn () => $service->record(1, $bundle->bundle_no, [
        'operation_id'=>$op1->id,'defect_id'=>$defect->id,'qty'=>11,
    ], $user))->toThrow(RuntimeException::class, 'tidak melebihi qty bundle');

    $rework = $service->record(1, $bundle->bundle_no, [
        'operation_id'=>$op1->id,'defect_id'=>$defect->id,'qty'=>1,
    ], $user);
    $service->resolve($rework, $user);
    expect(fn () => $service->resolve($rework->fresh(), $user))
        ->toThrow(RuntimeException::class, 'sudah diselesaikan');
});
