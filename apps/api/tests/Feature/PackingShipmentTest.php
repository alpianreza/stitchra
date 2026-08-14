<?php

use Modules\Core\Models\User;
use Modules\Inventory\Models\StockBalance;
use Modules\Packing\Services\PackingService;
use Modules\Qc\Services\QcService;
use Modules\Shipping\Services\ShipmentService;

test('BR-082: packing finalize DITOLAK tanpa QC FINAL PASS', function () {
    [$user, , $style, $so, $mo, $colorway, $size, $fgWh] = packFixture();

    $svc = app(PackingService::class);
    $pl = $svc->create($so, $mo->id, $user);
    $svc->addCarton($pl, [], [['style_id' => $style->id, 'colorway_id' => $colorway->id, 'size_id' => $size->id, 'qty' => 100]], $user);

    $svc->finalize($pl->fresh(), $fgWh->id, $user);
})->throws(RuntimeException::class);

test('BR-021/082: ratio check — packed melebihi order + toleransi → ditolak', function () {
    [$user, , $style, $so, $mo, $colorway, $size, $fgWh] = packFixture(soQty: 100, tolerance: 5);

    // QC PASS dulu
    $qc = app(QcService::class);
    $qc->finalize($qc->create($mo, 'FINAL', 100, $user), $user);

    $svc = app(PackingService::class);
    $pl = $svc->create($so, $mo->id, $user);
    // Order 100, toleransi 5% → maks 105; packing 106 harus gagal
    $svc->addCarton($pl, [], [['style_id' => $style->id, 'colorway_id' => $colorway->id, 'size_id' => $size->id, 'qty' => 106]], $user);

    $svc->finalize($pl->fresh(), $fgWh->id, $user);
})->throws(RuntimeException::class);

test('BR-082/013: finalize PASS → FG masuk gudang FG via ITS PRODUCTION_RECEIPT; MO → PACKED', function () {
    [$user, , $style, $so, $mo, $colorway, $size, $fgWh] = packFixture();

    $qc = app(QcService::class);
    $mo->update(['status' => 'FINISHING']);
    $qc->finalize($qc->create($mo, 'FINAL', 100, $user), $user);
    expect($mo->fresh()->status)->toBe('QC');

    $svc = app(PackingService::class);
    $pl = $svc->create($so, $mo->id, $user);
    $carton = $svc->addCarton($pl->fresh(), ['gross_weight_kg' => 12.5], [['style_id' => $style->id, 'colorway_id' => $colorway->id, 'size_id' => $size->id, 'qty' => 100]], $user);

    expect($carton->carton_no)->toStartWith($pl->doc_no.'-');

    $finalized = $svc->finalize($pl->fresh(), $fgWh->id, $user);
    expect($finalized->status)->toBe('APPROVED');
    expect($mo->fresh()->status)->toBe('PACKED');

    // FG balance per matrix variant
    $fg = StockBalance::withoutGlobalScopes()
        ->where('item_type', 'FG')->where('style_id', $style->id)
        ->where('colorway_id', $colorway->id)->where('size_id', $size->id)
        ->where('warehouse_id', $fgWh->id)->firstOrFail();
    expect((float) $fg->on_hand)->toBe(100.0);
    expect($fg->available())->toBe(100.0);
});

test('BR-021: shipment UNDER tanpa approval ditolak; setelah approve → ship + FG keluar', function () {
    [$user, , $style, $so, $mo, $colorway, $size, $fgWh] = packFixture(soQty: 100, tolerance: 5);

    $qc = app(QcService::class);
    $qc->finalize($qc->create($mo, 'FINAL', 90, $user), $user);

    $packing = app(PackingService::class);
    $pl = $packing->create($so, $mo->id, $user);
    // Pack 90 (under: −10% vs toleransi 5%)
    $packing->addCarton($pl->fresh(), [], [['style_id' => $style->id, 'colorway_id' => $colorway->id, 'size_id' => $size->id, 'qty' => 90]], $user);
    $pl = $packing->finalize($pl->fresh(), $fgWh->id, $user);

    $shipSvc = app(ShipmentService::class);
    $shipment = $shipSvc->create($pl, ['ship_date' => now()->toDateString(), 'forwarder' => 'Maersk', 'container_no' => 'MSCU123'], $user);

    expect($shipment->tolerance_check)->toBe('UNDER');

    // Ship tanpa approval → ditolak
    try {
        $shipSvc->ship($shipment->fresh(), $fgWh->id, $user);
        $this->fail('Seharusnya ditolak (BR-021)');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('BR-021');
    }

    // Approve eksplisit → ship OK; FG keluar; SO belum CLOSED (90 < 95)
    $shipment = $shipSvc->approveOverTolerance($shipment->fresh(), $user);
    $shipped = $shipSvc->ship($shipment->fresh(), $fgWh->id, $user);

    expect($shipped->status)->toBe('SHIPPED');
    $fg = StockBalance::withoutGlobalScopes()->where('item_type', 'FG')->where('style_id', $style->id)->where('warehouse_id', $fgWh->id)->firstOrFail();
    expect((float) $fg->on_hand)->toBe(0.0);
    expect($so->fresh()->status)->toBe('IN_PROGRESS');
});

test('shipment full qty dalam toleransi → SO CLOSED', function () {
    [$user, , $style, $so, $mo, $colorway, $size, $fgWh] = packFixture(soQty: 100, tolerance: 5);

    $qc = app(QcService::class);
    $qc->finalize($qc->create($mo, 'FINAL', 100, $user), $user);

    $packing = app(PackingService::class);
    $pl = $packing->create($so, $mo->id, $user);
    $packing->addCarton($pl->fresh(), [], [['style_id' => $style->id, 'colorway_id' => $colorway->id, 'size_id' => $size->id, 'qty' => 100]], $user);
    $pl = $packing->finalize($pl->fresh(), $fgWh->id, $user);

    $shipSvc = app(ShipmentService::class);
    $shipment = $shipSvc->create($pl, ['ship_date' => now()->toDateString()], $user);

    expect($shipment->tolerance_check)->toBe('OK');
    $shipSvc->ship($shipment, $fgWh->id, $user);

    expect($so->fresh()->status)->toBe('CLOSED');   // 100/100 terkirim
});
