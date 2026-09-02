<?php

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Finance\Models\Journal;
use Modules\Finance\Services\ValuationBoundaryService;
use Modules\Packing\Services\PackingService;
use Modules\Qc\Services\QcService;
use Modules\Shipping\Services\ShipmentService;

function iteration12FgFixture(bool $ship = false): array
{
    [$user, , $style, $so, $mo, $colorway, $size, $fg] = packFixture();
    $qc = app(QcService::class);
    $qc->finalize($qc->create($mo, 'FINAL', 100, $user), $user);
    $packing = app(PackingService::class);
    $pl = $packing->create($so, $mo->id, $user);
    $packing->addCarton($pl, [], [[
        'style_id' => $style->id, 'colorway_id' => $colorway->id,
        'size_id' => $size->id, 'qty' => 100,
    ]], $user);
    $pl = $packing->finalize($pl->fresh(), $fg->id, $user);
    $shipment = null;
    if ($ship) {
        $shipping = app(ShipmentService::class);
        $shipment = $shipping->create($pl, ['ship_date' => '2026-09-03'], $user);
        $shipment = $shipping->ship($shipment, $fg->id, $user);
    }
    return [$user, $mo->fresh(), $pl, $shipment];
}

test('matrix explicitly blocks undefined valuation without journal writes', function () {
    $user = User::factory()->create(['company_id' => 1]);
    $before = Journal::withoutGlobalScopes()->count();
    $rows = collect(app(ValuationBoundaryService::class)->authorityMatrix(1, $user)['rows'])->keyBy('boundary');
    expect($rows['Material Issue → WIP']['status'])->toBe('BLOCKED')
        ->and($rows['Material Return']['status'])->toBe('NOT DEFINED')
        ->and($rows['Production Output → FG']['reason'])->toBe('FG_VALUATION = NOT DEFINED')
        ->and($rows['FG → Shipment']['reason'])->toBe('SHIPMENT_VALUATION = NOT DEFINED')
        ->and($rows['Shipment → COGS']['posting_allowed'])->toBeFalse()
        ->and(Journal::withoutGlobalScopes()->count())->toBe($before);
});

test('Production Receipt remains valid quantity evidence but not FG valuation', function () {
    [$user, $mo, $pl] = iteration12FgFixture();
    $result = app(ValuationBoundaryService::class)->productionOrderBoundary($mo, $user);
    expect($result['production_output_to_fg']['quantity_status'])->toBe('DEFINED_BY_ITS_PRODUCTION_RECEIPT')
        ->and($result['production_output_to_fg']['source']['qty_in'])->toBe(100.0)
        ->and($result['production_output_to_fg']['source']['stored_cost_complete'])->toBeFalse()
        ->and($result['production_output_to_fg']['valuation_status'])->toBe('NOT DEFINED')
        ->and($result['actual_cost_dependency']['cost_per_unit'])->toBeNull()
        ->and(DB::table('stock_movements')->where('movement_type', 'PRODUCTION_RECEIPT')->where('source_document_id', $pl->id)->count())->toBe(1)
        ->and(Journal::withoutGlobalScopes()->where('event', 'PRODUCTION_RECEIPT')->count())->toBe(0);
});

test('Shipment stays operational while shipment valuation and COGS are blocked', function () {
    [$user, , , $shipment] = iteration12FgFixture(true);
    $result = app(ValuationBoundaryService::class)->shipmentBoundary($shipment, $user);
    expect($result['operational_source']['shipment_movement'])->not->toBeNull()
        ->and($result['operational_source']['shipment_ledger']['qty_out'])->toBe(100.0)
        ->and($result['shipment_valuation']['status'])->toBe('NOT DEFINED')
        ->and($result['cogs']['status'])->toBe('BLOCKED')
        ->and($result['cogs']['amount'])->toBeNull()
        ->and($result['cogs']['existing_journals'])->toBe([])
        ->and(Journal::withoutGlobalScopes()->where('event', 'SHIPMENT_COGS')->count())->toBe(0);
});

test('valuation boundary enforces company isolation', function () {
    [$user, $mo] = iteration12FgFixture();
    $otherCompany = DB::table('companies')->insertGetId([
        'code' => 'I12-'.uniqid(), 'name' => 'Other', 'base_currency' => 'IDR', 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $other = User::factory()->create(['company_id' => $otherCompany]);
    expect(fn () => app(ValuationBoundaryService::class)->productionOrderBoundary($mo, $other))
        ->toThrow(RuntimeException::class, 'akses');
    expect($user->company_id)->toBe(1);
});
