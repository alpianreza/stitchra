<?php

use Modules\Cutting\Models\Bundle;
use Modules\Cutting\Services\CuttingService;
use Modules\Receiving\Models\FabricRoll;
use Modules\ShopFloor\Models\ProductionScan;
use Modules\ShopFloor\Services\ScanService;

test('cut order mengubah MO RELEASED → CUTTING (BR-012)', function () {
    [$user, , , , , , $mo, , , $colorway, $size] = shopFixture();

    $cut = app(CuttingService::class)->create($mo, [
        ['colorway_id' => $colorway->id, 'size_id' => $size->id, 'qty_cut' => 25],
    ], $user);

    expect($cut->status)->toBe('IN_PROGRESS');
    expect($cut->doc_no)->toStartWith('CUT-');
    expect($mo->fresh()->status)->toBe('CUTTING');
    expect($mo->fresh()->actual_start)->not->toBeNull();
});

test('BR-031/041: marker log mengonsumsi roll aktual; complete meng-update consumption_actual BOM', function () {
    [$user, $fabric, , , , , $mo, , , $colorway, $size] = shopFixture();
    $roll = FabricRoll::where('roll_no', 'R001')->firstOrFail();   // 300 m

    $svc = app(CuttingService::class);
    $cut = $svc->create($mo, [['colorway_id' => $colorway->id, 'size_id' => $size->id, 'qty_cut' => 100]], $user);

    // Pakai 190 m untuk 100 pcs → aktual 1.9 m/pcs (vs estimasi 2.0)
    $svc->recordMarker($cut, [[
        'roll_id' => $roll->id, 'marker_length_m' => 9.5, 'plies' => 20,
        'qty_fabric_used_m' => 190, 'efficiency_pct' => 92,
    ]], $user);

    expect((float) $roll->fresh()->qty_remaining_meter)->toBe(110.0);

    $svc->complete($cut->fresh(), $user);

    $bomLine = $mo->fresh()->bomVersion->lines()->where('material_id', $fabric->id)->firstOrFail();
    expect((float) $bomLine->consumption_actual)->toBe(1.9);   // BR-031: estimated vs actual terpisah
});

test('BR-061: generate bundles — qty_cut 25, size 10 → 3 bundle (10/10/5), nomor unik', function () {
    [$user, , , , , , $mo, , , $colorway, $size] = shopFixture();

    $svc = app(CuttingService::class);
    $cut = $svc->create($mo, [['colorway_id' => $colorway->id, 'size_id' => $size->id, 'qty_cut' => 25]], $user);
    $line = $cut->lines->first();

    $bundles = $svc->generateBundles($cut, $line->id, 10, $user);

    expect($bundles)->toHaveCount(3);
    expect((float) $bundles[0]->qty)->toBe(10.0);
    expect((float) $bundles[2]->qty)->toBe(5.0);
    expect(collect($bundles)->pluck('bundle_no')->unique())->toHaveCount(3);
    expect($bundles[0]->current_stage)->toBe('CUTTING');

    // Generate ulang ditolak
    $svc->generateBundles($cut, $line->id, 10, $user);
})->throws(RuntimeException::class);

test('BR-062: urutan scan divalidasi — IN op2 tanpa OUT op1 ditolak; OUT tanpa IN ditolak; double IN ditolak', function () {
    [$user, , , , , , $mo, $op1, $op2, $colorway, $size] = shopFixture();

    $cut = app(CuttingService::class)->create($mo, [['colorway_id' => $colorway->id, 'size_id' => $size->id, 'qty_cut' => 10]], $user);
    $bundle = app(CuttingService::class)->generateBundles($cut, $cut->lines->first()->id, 10, $user)[0];

    $scan = app(ScanService::class);

    // OUT tanpa IN → tolak
    try {
        $scan->scan(1, $bundle->bundle_no, ['operation_id' => $op1->id, 'direction' => 'OUT', 'stage' => 'SEWING'], $user);
        $this->fail('OUT tanpa IN harus ditolak');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('OUT tanpa IN');
    }

    // IN op2 sebelum OUT op1 → tolak (urutan routing)
    try {
        $scan->scan(1, $bundle->bundle_no, ['operation_id' => $op2->id, 'direction' => 'IN', 'stage' => 'SEWING'], $user);
        $this->fail('IN op2 sebelum OUT op1 harus ditolak');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('operasi sebelumnya');
    }

    // Alur benar: IN op1 → OUT op1 → IN op2
    $scan->scan(1, $bundle->bundle_no, ['operation_id' => $op1->id, 'direction' => 'IN', 'stage' => 'SEWING'], $user);

    // MO naik ke SEWING (BR-012)
    expect($mo->fresh()->status)->toBe('SEWING');

    // Double IN → tolak
    try {
        $scan->scan(1, $bundle->bundle_no, ['operation_id' => $op1->id, 'direction' => 'IN', 'stage' => 'SEWING'], $user);
        $this->fail('Double IN harus ditolak');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('double IN');
    }

    $scan->scan(1, $bundle->bundle_no, ['operation_id' => $op1->id, 'direction' => 'OUT', 'stage' => 'SEWING'], $user);
    $inOp2 = $scan->scan(1, $bundle->bundle_no, ['operation_id' => $op2->id, 'direction' => 'IN', 'stage' => 'SEWING'], $user);

    expect($inOp2->direction)->toBe('IN');
    expect($bundle->fresh()->current_stage)->toBe('SEWING');
    expect(ProductionScan::where('bundle_id', $bundle->id)->count())->toBe(4);
});

test('BR-063: WIP per stage dari bundle; daily output dari scan OUT', function () {
    [$user, , , , , , $mo, $op1, $op2, $colorway, $size] = shopFixture();

    $cut = app(CuttingService::class)->create($mo, [['colorway_id' => $colorway->id, 'size_id' => $size->id, 'qty_cut' => 30]], $user);
    $bundles = app(CuttingService::class)->generateBundles($cut, $cut->lines->first()->id, 10, $user);   // 3×10

    $scan = app(ScanService::class);
    $line = \Modules\MasterData\Models\Line::create(['company_id' => 1, 'code' => 'LN-'.uniqid(), 'name' => 'Line A']);
    $mo->update(['line_id' => $line->id]);

    // Bundle 1 & 2 masuk sewing, bundle 3 tetap di cutting
    foreach ([$bundles[0], $bundles[1]] as $b) {
        $scan->scan(1, $b->bundle_no, ['operation_id' => $op1->id, 'direction' => 'IN', 'stage' => 'SEWING', 'line_id' => $line->id], $user);
    }
    $scan->scan(1, $bundles[0]->bundle_no, ['operation_id' => $op1->id, 'direction' => 'OUT', 'stage' => 'SEWING', 'line_id' => $line->id], $user);

    $wip = $scan->wipByStage($mo->id);
    expect($wip['SEWING']['bundles'])->toBe(2);
    expect($wip['SEWING']['pcs'])->toBe(20.0);
    expect($wip['CUTTING']['bundles'])->toBe(1);

    $daily = $scan->dailyOutput($line->id, now()->toDateString());
    expect(collect($daily)->sum('pcs'))->toBe(10.0);   // hanya bundle 1 yang OUT
});
