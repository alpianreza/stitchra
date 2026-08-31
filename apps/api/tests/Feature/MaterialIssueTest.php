<?php

use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockReservation;
use Modules\Production\Services\MaterialIssueService;
use Modules\Receiving\Models\FabricRoll;

test('BR-060: actual issue memperbarui reservation dan balance roll yang sama', function () {
    [$user, $fabric, , $uomMtr, , $warehouse, $mo] = shopFixture();
    $roll = FabricRoll::where('roll_no', 'R001')->firstOrFail();

    $issue = app(MaterialIssueService::class)->issue($mo, $warehouse->id, [[
        'material_id' => $fabric->id, 'qty' => 80,
        'uom_id' => $uomMtr->id, 'roll_id' => $roll->id,
    ]], $user);

    $reservation = StockReservation::withoutGlobalScopes()->where('mo_id', $mo->id)
        ->where('material_id', $fabric->id)->where('roll_id', $roll->id)->firstOrFail();
    $balance = StockBalance::withoutGlobalScopes()->where('material_id', $fabric->id)
        ->where('warehouse_id', $warehouse->id)->where('roll_id', $roll->id)->firstOrFail();

    expect($issue->mode)->toBe('ACTUAL')
        ->and((float) $reservation->qty_issued)->toBe(80.0)
        ->and($reservation->status)->toBe('PARTIAL_ISSUED')
        ->and((float) $balance->on_hand)->toBe(220.0)
        ->and((float) $balance->reserved)->toBe(120.0);
});

test('BR-060: issue melebihi sisa reservation ditolak', function () {
    [$user, $fabric, , $uomMtr, , $warehouse, $mo] = shopFixture();
    $roll = FabricRoll::where('roll_no', 'R001')->firstOrFail();
    app(MaterialIssueService::class)->issue($mo, $warehouse->id, [[
        'material_id' => $fabric->id, 'qty' => 201,
        'uom_id' => $uomMtr->id, 'roll_id' => $roll->id,
    ]], $user);
})->throws(RuntimeException::class);

test('BR-041: fabric roll-tracked wajib memakai roll reservation', function () {
    [$user, $fabric, , $uomMtr, , $warehouse, $mo] = shopFixture();
    app(MaterialIssueService::class)->issue($mo, $warehouse->id, [[
        'material_id' => $fabric->id, 'qty' => 50, 'uom_id' => $uomMtr->id,
    ]], $user);
})->throws(RuntimeException::class);

test('BR-041: backflush trim sama dengan target kumulatif BOM kali qty produced', function () {
    [$user, , $trim, , , $warehouse, $mo] = shopFixture();
    $mo->update(['qty_produced' => 100]);

    $issue = app(MaterialIssueService::class)->backflush($mo->fresh(), $warehouse->id, $user);
    $balance = StockBalance::withoutGlobalScopes()->where('material_id', $trim->id)
        ->where('warehouse_id', $warehouse->id)->firstOrFail();
    $reservation = StockReservation::withoutGlobalScopes()->where('mo_id', $mo->id)
        ->where('material_id', $trim->id)->firstOrFail();

    expect((float) $issue->lines->sum('qty'))->toBe(500.0)
        ->and((float) $balance->on_hand)->toBe(500.0)
        ->and((float) $reservation->qty_issued)->toBe(500.0)
        ->and($reservation->status)->toBe('FULLY_ISSUED');
});

test('BR-042: leftover roll menunggu model dispatch dan consumption quantity eksplisit')
    ->todo('Pisahkan qty di gudang, qty dispatched ke cutting, qty consumed, dan qty returned agar stok tidak double-count.');
