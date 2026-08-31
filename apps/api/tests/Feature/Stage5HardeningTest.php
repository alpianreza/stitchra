<?php

use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockReservation;
use Modules\Planning\Models\MrpRun;
use Modules\Planning\Services\MrpService;
use Modules\Production\Services\MaterialIssueService;
use Modules\Receiving\Models\FabricRoll;

it('release MO mengalokasikan fabric ke balance dan reservation roll yang sama', function () {
    [$user, $fabric, , , , $warehouse, $mo] = shopFixture();
    $roll = FabricRoll::where('roll_no', 'R001')->firstOrFail();
    $reservation = StockReservation::withoutGlobalScopes()
        ->where('mo_id', $mo->id)->where('material_id', $fabric->id)->firstOrFail();
    $balance = StockBalance::withoutGlobalScopes()
        ->where('material_id', $fabric->id)->where('warehouse_id', $warehouse->id)
        ->where('roll_id', $roll->id)->firstOrFail();

    expect($reservation->roll_id)->toBe($roll->id)
        ->and((float) $reservation->qty_reserved)->toBe(200.0)
        ->and((float) $balance->reserved)->toBe(200.0)
        ->and($balance->available())->toBe(100.0);
});

it('actual issue mengurangi reservation dan balance pada roll yang sama', function () {
    [$user, $fabric, , $uom, , $warehouse, $mo] = shopFixture();
    $roll = FabricRoll::where('roll_no', 'R001')->firstOrFail();

    app(MaterialIssueService::class)->issue($mo, $warehouse->id, [[
        'material_id' => $fabric->id, 'qty' => 80,
        'uom_id' => $uom->id, 'roll_id' => $roll->id,
    ]], $user);

    $reservation = StockReservation::withoutGlobalScopes()->where('mo_id', $mo->id)
        ->where('material_id', $fabric->id)->where('roll_id', $roll->id)->firstOrFail();
    $balance = StockBalance::withoutGlobalScopes()->where('material_id', $fabric->id)
        ->where('warehouse_id', $warehouse->id)->where('roll_id', $roll->id)->firstOrFail();
    expect((float) $reservation->qty_issued)->toBe(80.0)
        ->and((float) $balance->on_hand)->toBe(220.0)
        ->and((float) $balance->reserved)->toBe(120.0);
});

it('backflush hanya memposting delta qty produced yang belum pernah diproses', function () {
    [$user, , $trim, , , $warehouse, $mo] = shopFixture();
    $service = app(MaterialIssueService::class);

    $mo->update(['qty_produced' => 50]);
    $first = $service->backflush($mo->fresh(), $warehouse->id, $user);
    $mo->update(['qty_produced' => 100]);
    $second = $service->backflush($mo->fresh(), $warehouse->id, $user);
    $third = $service->backflush($mo->fresh(), $warehouse->id, $user);

    expect((float) $first->lines->sum('qty'))->toBe(250.0)
        ->and((float) $second->lines->sum('qty'))->toBe(250.0)
        ->and($third)->toBeNull();
    $reservation = StockReservation::withoutGlobalScopes()->where('mo_id', $mo->id)
        ->where('material_id', $trim->id)->firstOrFail();
    expect((float) $reservation->qty_issued)->toBe(500.0)
        ->and($reservation->status)->toBe('FULLY_ISSUED');
});

it('MRP menolak selection parsial dan tidak menyimpan run setengah jadi', function () {
    [$user, , , , , , $mo] = shopFixture();

    expect(fn () => app(MrpService::class)->run(1, [
        'so_ids' => [$mo->sales_order_id, 999999999],
    ], $user))->toThrow(RuntimeException::class, 'Seluruh SO');
    expect(MrpRun::withoutGlobalScopes()->count())->toBe(0);
});
