<?php

use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockReservation;
use Modules\Production\Services\MaterialIssueService;
use Modules\Receiving\Models\FabricRoll;

test('BR-060: issue aktual dari reservasi — reservasi & alokasi ter-update, stok RM turun', function () {
    [$user, $fabric, $trim, $uomMtr, $uomPcs, $warehouse, $mo] = shopFixture();
    $roll = FabricRoll::where('roll_no', 'R001')->firstOrFail();

    $issue = app(MaterialIssueService::class)->issue($mo, $warehouse->id, [[
        'material_id' => $fabric->id, 'qty' => 80, 'uom_id' => $uomMtr->id, 'roll_id' => $roll->id,
    ]], $user);

    expect($issue->mode)->toBe('ACTUAL');
    expect($issue->doc_no)->toStartWith('MI-');

    $res = StockReservation::where('mo_id', $mo->id)->where('material_id', $fabric->id)->firstOrFail();
    expect((float) $res->qty_issued)->toBe(80.0);
    expect($res->status)->toBe('PARTIAL_ISSUED');

    // Saldo fabric: on_hand 500 − 80 = 420; reserved 200 − 80 = 120
    $b = StockBalance::withoutGlobalScopes()->where('material_id', $fabric->id)->where('warehouse_id', $warehouse->id)->whereNull('roll_id')->firstOrFail();
    expect((float) $b->on_hand)->toBe(420.0);
    expect((float) $b->reserved)->toBe(120.0);
});

test('BR-060: issue melebihi sisa reservasi ditolak', function () {
    [$user, $fabric, , $uomMtr, , $warehouse, $mo] = shopFixture();
    $roll = FabricRoll::where('roll_no', 'R001')->firstOrFail();

    app(MaterialIssueService::class)->issue($mo, $warehouse->id, [[
        'material_id' => $fabric->id, 'qty' => 201, 'uom_id' => $uomMtr->id, 'roll_id' => $roll->id,  // reservasi 200
    ]], $user);
})->throws(RuntimeException::class);

test('BR-041: fabric roll-tracked wajib per roll — issue tanpa roll_id ditolak', function () {
    [$user, $fabric, , $uomMtr, , $warehouse, $mo] = shopFixture();

    app(MaterialIssueService::class)->issue($mo, $warehouse->id, [[
        'material_id' => $fabric->id, 'qty' => 50, 'uom_id' => $uomMtr->id,   // tanpa roll_id
    ]], $user);
})->throws(RuntimeException::class);

test('BR-041: backflush trim = BOM × qty_produced persis', function () {
    [$user, , $trim, , $uomPcs, $warehouse, $mo] = shopFixture();

    $mo->update(['qty_produced' => 100]);
    $issue = app(MaterialIssueService::class)->backflush($mo, $warehouse->id, $user);

    expect($issue->mode)->toBe('BACKFLUSH');
    $line = $issue->lines->first();
    expect($line->material_id)->toBe($trim->id);
    expect((float) $line->qty)->toBe(500.0);   // 5 pcs × 100

    $b = StockBalance::withoutGlobalScopes()->where('material_id', $trim->id)->where('warehouse_id', $warehouse->id)->firstOrFail();
    expect((float) $b->on_hand)->toBe(500.0);  // 1000 − 500
});

test('BR-042: leftover roll kembali ke stok available; roll CONSUMED', function () {
    [$user, $fabric, , $uomMtr, , $warehouse, $mo] = shopFixture();
    $roll = FabricRoll::where('roll_no', 'R001')->firstOrFail();   // 300 m tersisa

    // Simulasi pemakaian: konsumsi 220 → sisa 80
    $roll->consume(220);

    $return = app(MaterialIssueService::class)->returnLeftover($mo, $roll->fresh(), $warehouse->id, $user, 'Sisa marker');

    expect((float) $return->qty_returned_meter)->toBe(80.0);
    expect($roll->fresh()->status)->toBe('CONSUMED');
    expect((float) $roll->fresh()->qty_remaining_meter)->toBe(0.0);

    // Stok bertambah kembali via PRODUCTION_RETURN (available — bukan hold)
    $b = StockBalance::withoutGlobalScopes()->where('material_id', $fabric->id)->where('warehouse_id', $warehouse->id)->whereNotNull('roll_id')->first();
    expect($b)->not->toBeNull();
    expect((float) $b->on_hand)->toBe(80.0);
    expect((float) $b->quality_hold)->toBe(0.0);
});
