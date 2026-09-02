<?php

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Finance\Models\Journal;
use Modules\Packing\Services\PackingService;
use Modules\Qc\Services\QcService;
use Modules\Shipping\Services\CommercialFulfillmentService;
use Modules\Shipping\Services\ShipmentService;

function iteration14Fixture(float $qty = 100): array
{
    [$user, , $style, $so, $mo, $colorway, $size, $fg] = packFixture($qty);
    $qc = app(QcService::class);
    $inspection = $qc->finalize($qc->create($mo, 'FINAL', $qty, $user), $user);
    return [$user, $style, $so, $mo, $colorway, $size, $fg, $inspection];
}

function iteration14Packing(array $fixture, float $qty): object
{
    [$user, $style, $so, $mo, $colorway, $size, $fg] = $fixture;
    $packing = app(PackingService::class);
    $list = $packing->create($so, $mo->id, $user);
    $packing->addCarton($list, [], [[
        'style_id' => $style->id, 'colorway_id' => $colorway->id,
        'size_id' => $size->id, 'qty' => $qty,
    ]], $user);
    return $packing->finalize($list->fresh(), $fg->id, $user);
}

test('commercial authority matrix preserves defined sources and blocks unsupported documents', function () {
    $user = User::factory()->create(['company_id' => 1]);
    $result = app(CommercialFulfillmentService::class)->authorityMatrix(1, $user);
    $rows = collect($result['rows'])->keyBy('boundary');

    expect($rows['SO Matrix quantity']['status'])->toBe('DEFINED')
        ->and($rows['Shipment quantity']['status'])->toBe('DEFINED')
        ->and($rows['Delivery Schedule quantity']['status'])->toBe('PARTIAL')
        ->and($rows['Delivery Schedule → Shipment']['status'])->toBe('NOT DEFINED')
        ->and($rows['Partial shipment']['status'])->toBe('PARTIAL')
        ->and($rows['Commercial Invoice']['status'])->toBe('NOT DEFINED')
        ->and($rows['Export documents']['status'])->toBe('NOT DEFINED')
        ->and($result['states']['COGS'])->toBe('NOT DEFINED')
        ->and($result['writes_performed'])->toBeFalse()
        ->and($result['migration'])->toBe('NONE');
});

test('delivery schedule is visible through SO but is not allocated to shipment', function () {
    [$user, , $so] = iteration14Fixture(100);
    $scheduleId = DB::table('delivery_schedules')->insertGetId([
        'sales_order_id' => $so->id, 'delivery_date' => '2026-09-20',
        'qty' => 60, 'destination' => 'Jakarta', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $before = DB::table('delivery_schedules')->count();
    $result = app(CommercialFulfillmentService::class)->salesOrder($so, $user);

    expect($result['delivery_schedules']['rows'][0]['id'])->toBe($scheduleId)
        ->and($result['delivery_schedules']['scheduled_qty'])->toBe(60.0)
        ->and($result['delivery_schedules']['shipment_link'])->toBe('NOT DEFINED')
        ->and($result['delivery_schedules']['remaining_qty'])->toBeNull()
        ->and($result['matrix'][0]['ordered_qty'])->toBe(100.0)
        ->and($result['matrix'][0]['shipped_qty'])->toBe(0.0)
        ->and(DB::table('delivery_schedules')->count())->toBe($before);
});

test('multiple full Packing List shipments fulfill one SO cumulatively without schedule allocation', function () {
    $fixture = iteration14Fixture(100);
    [$user, , $so, , , , $fg] = $fixture;
    $shipping = app(ShipmentService::class);

    $firstList = iteration14Packing($fixture, 40);
    $first = $shipping->create($firstList, ['ship_date' => '2026-09-10'], $user);
    expect($first->tolerance_check)->toBe('UNDER');
    $shipping->approveOverTolerance($first, $user);
    $shipping->ship($first->fresh(), $fg->id, $user);

    $secondList = iteration14Packing($fixture, 60);
    $second = $shipping->create($secondList, ['ship_date' => '2026-09-15'], $user);
    expect($second->tolerance_check)->toBe('OK');
    $shipping->ship($second, $fg->id, $user);

    $result = app(CommercialFulfillmentService::class)->salesOrder($so->fresh(), $user);
    expect($result['matrix'][0]['shipped_qty'])->toBe(100.0)
        ->and($result['matrix'][0]['remaining_to_order_qty'])->toBe(0.0)
        ->and($result['shipments'])->toHaveCount(2)
        ->and($result['partial_shipment']['status'])->toBe('PARTIAL')
        ->and($result['delivery_schedules']['shipment_link'])->toBe('NOT DEFINED')
        ->and($so->fresh()->status)->toBe('CLOSED');
});

test('shipment commercial lineage keeps ITS quantity and blocks valuation COGS and commercial documents', function () {
    $fixture = iteration14Fixture(100);
    [$user, , , , , , $fg] = $fixture;
    $list = iteration14Packing($fixture, 100);
    $shipping = app(ShipmentService::class);
    $shipment = $shipping->create($list, ['ship_date' => '2026-09-16'], $user);
    $shipping->ship($shipment, $fg->id, $user);
    $journalCount = Journal::withoutGlobalScopes()->where('event', 'SHIPMENT_COGS')->count();

    $result = app(CommercialFulfillmentService::class)->shipment($shipment->fresh(), $user);
    expect($result['packing_source']['authority'])->toBe('PACKING_LIST_CARTON_MATRIX')
        ->and($result['its_shipment']['status'])->toBe('POSTED')
        ->and($result['delivery_schedule_link']['status'])->toBe('NOT DEFINED')
        ->and($result['commercial_documents']['commercial_invoice'])->toBe('NOT DEFINED')
        ->and($result['commercial_documents']['export_documents'])->toBe('NOT DEFINED')
        ->and($result['valuation']['shipment'])->toBe('NOT DEFINED')
        ->and($result['valuation']['cogs'])->toBe('NOT DEFINED')
        ->and($result['valuation']['cogs_journal_allowed'])->toBeFalse()
        ->and(Journal::withoutGlobalScopes()->where('event', 'SHIPMENT_COGS')->count())->toBe($journalCount)
        ->and($result['lineage']['forward'])->toContain('Delivery Schedule link unavailable')
        ->and($result['lineage']['reverse'])->toContain('SO Matrix');
});

test('commercial fulfillment enforces company isolation', function () {
    [, , $so] = iteration14Fixture(20);
    $company = DB::table('companies')->insertGetId([
        'code' => 'I14-'.uniqid(), 'name' => 'Other', 'base_currency' => 'IDR', 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $other = User::factory()->create(['company_id' => $company]);
    expect(fn () => app(CommercialFulfillmentService::class)->salesOrder($so, $other))
        ->toThrow(RuntimeException::class, 'akses');
});
