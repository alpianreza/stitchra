<?php

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\StockBalance;
use Modules\MasterData\Models\Warehouse;
use Modules\Packing\Models\PackingList;
use Modules\Packing\Services\PackingService;
use Modules\Qc\Services\QcService;
use Modules\Shipping\Services\ShipmentService;


it('mengekspos eligible FG dengan source Packing List dan PRODUCTION_RECEIPT', function () {
    [$user, , , , , , , $fg, $pl] = approvedFgFixture();
    $eligible = app(ShipmentService::class)->eligibleFg(1, $user);
    expect($eligible)->toHaveCount(1)
        ->and($eligible[0]['packing_list_id'])->toBe($pl->id)
        ->and($eligible[0]['warehouse_id'])->toBe($fg->id)
        ->and($eligible[0]['received_qty'])->toBe(100.0)
        ->and($eligible[0]['available_qty'])->toBe(100.0);
    expect(DB::table('stock_movements')->where('movement_type', 'PRODUCTION_RECEIPT')->where('source_document_type', 'packing_lists')->where('source_document_id', $pl->id)->count())->toBe(1);
});

it('menolak shipment dari Packing List APPROVED tanpa PRODUCTION_RECEIPT traceable', function () {
    [$user, , , $so, $mo] = packFixture();
    $qc = app(QcService::class);
    $inspection = $qc->create($mo, 'FINAL', 100, $user);
    $qc->finalize($inspection, $user);
    $pl = PackingList::create(['company_id' => 1, 'doc_no' => 'PL-NO-RECEIPT', 'sales_order_id' => $so->id, 'production_order_id' => $mo->id, 'qc_inspection_id' => $inspection->id, 'status' => 'APPROVED', 'created_by' => $user->id]);
    expect(fn () => app(ShipmentService::class)->create($pl, ['ship_date' => now()->toDateString()], $user))
        ->toThrow(RuntimeException::class, 'PRODUCTION_RECEIPT');
});

it('mengunci shipment ke warehouse sumber receipt dan mencegah double stock out', function () {
    [$user, , $style, , , , , $fg, $pl] = approvedFgFixture();
    $otherFg = Warehouse::create(['company_id' => 1, 'code' => 'FG-OTHER', 'name' => 'Other FG', 'type' => 'FG', 'is_active' => true]);
    $shipping = app(ShipmentService::class);
    $shipment = $shipping->create($pl, ['ship_date' => now()->toDateString()], $user);
    expect(fn () => $shipping->ship($shipment, $otherFg->id, $user))->toThrow(RuntimeException::class, 'warehouse FG sumber');
    $shipped = $shipping->ship($shipment->fresh(), $fg->id, $user);
    expect($shipped->status)->toBe('SHIPPED')
        ->and(DB::table('stock_movements')->where('movement_type', 'SHIPMENT')->where('source_document_id', $shipment->id)->count())->toBe(1);
    expect(fn () => $shipping->ship($shipment->fresh(), $fg->id, $user))->toThrow(RuntimeException::class, 'tidak bisa dikirim');
    $balance = StockBalance::withoutGlobalScopes()->where('item_type', 'FG')->where('style_id', $style->id)->where('warehouse_id', $fg->id)->firstOrFail();
    expect((float) $balance->on_hand)->toBe(0.0);
});

it('menyediakan reverse lineage ITS SHIPMENT ke QC FINAL dan SO', function () {
    [$user, , , $so, $mo, , , $fg, $pl] = approvedFgFixture();
    $shipping = app(ShipmentService::class);
    $shipment = $shipping->create($pl, ['ship_date' => now()->toDateString()], $user);
    $shipping->ship($shipment, $fg->id, $user);
    $lineage = $shipping->lineage($shipment->fresh(), $user);
    expect($lineage['shipment_movement']['movement_type'])->toBe('SHIPMENT')
        ->and($lineage['packing_list']['id'])->toBe($pl->id)
        ->and($lineage['production_order']['id'])->toBe($mo->id)
        ->and($lineage['sales_order']['id'])->toBe($so->id)
        ->and($lineage['qc_final']['verdict'])->toBe('PASS');
});
