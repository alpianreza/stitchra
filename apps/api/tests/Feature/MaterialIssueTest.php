<?php

use Modules\Cutting\Services\CuttingService;
use Modules\Cutting\Services\LayExecutionService;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockReservation;
use Modules\MasterData\Models\ShadeGroup;
use Modules\Production\Services\MaterialIssueService;
use Modules\Receiving\Models\FabricRoll;

test('BR-060 actual issue memperbarui reservation dan balance roll sama', function () {
    [$user, $fabric, , $uom, , $warehouse, $mo] = shopFixture(); $roll = FabricRoll::where('roll_no', 'R001')->firstOrFail();
    $issue = app(MaterialIssueService::class)->issue($mo, $warehouse->id, [['material_id' => $fabric->id, 'qty' => 80, 'uom_id' => $uom->id, 'roll_id' => $roll->id]], $user);
    $reservation = StockReservation::withoutGlobalScopes()->where('mo_id', $mo->id)->where('material_id', $fabric->id)->where('roll_id', $roll->id)->firstOrFail();
    $balance = StockBalance::withoutGlobalScopes()->where('material_id', $fabric->id)->where('warehouse_id', $warehouse->id)->where('roll_id', $roll->id)->firstOrFail();
    expect($issue->mode)->toBe('ACTUAL')->and((float) $reservation->qty_issued)->toBe(80.0)->and($reservation->status)->toBe('PARTIAL_ISSUED')
        ->and((float) $balance->on_hand)->toBe(220.0)->and((float) $balance->reserved)->toBe(120.0);
});

test('BR-066 menolak ACTUAL issue untuk material BACKFLUSH', function () {
    [$user, , $trim, , $pcs, $warehouse, $mo] = shopFixture();
    expect(fn () => app(MaterialIssueService::class)->issue($mo, $warehouse->id, [['material_id' => $trim->id, 'qty' => 5, 'uom_id' => $pcs->id]], $user))
        ->toThrow(RuntimeException::class, 'BR-066');
});

test('BR-041 fabric wajib roll reservation', function () {
    [$user, $fabric, , $uom, , $warehouse, $mo] = shopFixture();
    expect(fn () => app(MaterialIssueService::class)->issue($mo, $warehouse->id, [['material_id' => $fabric->id, 'qty' => 50, 'uom_id' => $uom->id]], $user))
        ->toThrow(RuntimeException::class, 'BR-041');
});

test('BR-066 backflush memakai target kumulatif dari Named Stage CUT_OUTPUT', function () {
    [$user, $fabric, $trim, $uom, , $warehouse, $mo, , , $colorway, $size] = shopFixture();
    $roll = FabricRoll::where('roll_no', 'R001')->firstOrFail();
    app(MaterialIssueService::class)->issue($mo, $warehouse->id, [['material_id' => $fabric->id, 'qty' => 100, 'uom_id' => $uom->id, 'roll_id' => $roll->id]], $user);
    $shade = ShadeGroup::create(['company_id' => 1, 'code' => 'BF-'.uniqid(), 'name' => 'Backflush']); $roll->update(['shade_group_id' => $shade->id]);
    $cut = app(CuttingService::class)->create($mo->fresh(), [['colorway_id' => $colorway->id, 'size_id' => $size->id, 'qty_cut' => 100]], $user);
    $lays = app(LayExecutionService::class); $lay = $lays->createLay($cut, 20, $user); $lays->addRoll($lay, $roll->fresh(), 100, $user);
    $output = $lays->createOutput($lay->fresh(), $cut->lines->first()->id, 100, $user); $lays->generateBundles($output, 20, $user); $lays->completeLay($lay->fresh(), $user);
    $issue = app(MaterialIssueService::class)->backflush($mo->fresh(), $warehouse->id, $user);
    $line = $issue->lines->first(); $reservation = StockReservation::withoutGlobalScopes()->where('mo_id', $mo->id)->where('material_id', $trim->id)->firstOrFail();
    expect((float) $issue->lines->sum('qty'))->toBe(500.0)->and($line->backflush_stage)->toBe('CUT_OUTPUT')
        ->and((float) $reservation->qty_issued)->toBe(500.0)->and($reservation->status)->toBe('FULLY_ISSUED');
    expect(app(MaterialIssueService::class)->backflush($mo->fresh(), $warehouse->id, $user))->toBeNull();
});
