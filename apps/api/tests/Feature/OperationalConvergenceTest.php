<?php

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Cutting\Models\Lay;
use Modules\Cutting\Models\LayRoll;
use Modules\Cutting\Services\CuttingService;
use Modules\Cutting\Services\LayExecutionService;
use Modules\MasterData\Models\ShadeGroup;
use Modules\Production\Services\MaterialIssueService;
use Modules\Production\Services\OperationalIntegrityService;
use Modules\Receiving\Models\FabricRoll;

function iteration15CutFixture(): array
{
    $fixture = shopFixture();
    [$user, $fabric, , $uom, , $warehouse, $mo, , , $colorway, $size] = $fixture;
    $roll = FabricRoll::where('roll_no', 'R001')->firstOrFail();
    app(MaterialIssueService::class)->issue($mo, $warehouse->id, [[
        'material_id' => $fabric->id, 'qty' => 100, 'uom_id' => $uom->id, 'roll_id' => $roll->id,
    ]], $user);
    $cut = app(CuttingService::class)->create($mo->fresh(), [[
        'colorway_id' => $colorway->id, 'size_id' => $size->id, 'qty_cut' => 100,
    ]], $user);
    return [$user, $fabric, $uom, $warehouse, $mo->fresh(), $roll->fresh(), $cut];
}

test('legacy Marker consumption blocks subsequent Lay Roll without mutating history', function () {
    [$user, , , , $mo, $roll, $cut] = iteration15CutFixture();
    $cutting = app(CuttingService::class);
    $cutting->recordMarker($cut, [[
        'roll_id' => $roll->id, 'marker_length_m' => 2, 'plies' => 20,
        'qty_fabric_used_m' => 40, 'efficiency_pct' => 90,
    ]], $user);
    $lay = app(LayExecutionService::class)->createLay($cut->fresh(), 20, $user);
    $dispatchBefore = (float) DB::table('fabric_dispatch_balances')->where('production_order_id', $mo->id)->where('roll_id', $roll->id)->value('qty_consumed');
    $remainingBefore = (float) $roll->fresh()->qty_remaining_meter;

    expect(fn () => app(LayExecutionService::class)->addRoll($lay, $roll->fresh(), 10, $user))
        ->toThrow(RuntimeException::class, 'CUTTING_CONSUMPTION_CONFLICT')
        ->and(DB::table('lay_rolls')->where('lay_id', $lay->id)->count())->toBe(0)
        ->and((float) DB::table('fabric_dispatch_balances')->where('production_order_id', $mo->id)->where('roll_id', $roll->id)->value('qty_consumed'))->toBe($dispatchBefore)
        ->and((float) $roll->fresh()->qty_remaining_meter)->toBe($remainingBefore);
});

test('Lay Roll consumption blocks subsequent legacy Marker consumption', function () {
    [$user, , , , $mo, $roll, $cut] = iteration15CutFixture();
    $shade = ShadeGroup::create(['company_id' => 1, 'code' => 'I15-'.uniqid(), 'name' => 'Iteration 15']);
    $roll->update(['shade_group_id' => $shade->id]);
    $layService = app(LayExecutionService::class);
    $lay = $layService->createLay($cut, 20, $user);
    $layService->addRoll($lay, $roll->fresh(), 40, $user);
    $dispatchBefore = (float) DB::table('fabric_dispatch_balances')->where('production_order_id', $mo->id)->where('roll_id', $roll->id)->value('qty_consumed');

    expect(fn () => app(CuttingService::class)->recordMarker($cut->fresh(), [[
        'roll_id' => $roll->id, 'marker_length_m' => 1, 'plies' => 10,
        'qty_fabric_used_m' => 10, 'efficiency_pct' => 90,
    ]], $user))->toThrow(RuntimeException::class, 'CUTTING_CONSUMPTION_CONFLICT')
        ->and(DB::table('marker_logs')->where('cut_order_id', $cut->id)->count())->toBe(0)
        ->and((float) DB::table('fabric_dispatch_balances')->where('production_order_id', $mo->id)->where('roll_id', $roll->id)->value('qty_consumed'))->toBe($dispatchBefore);
});

test('historical mixed Marker and Lay evidence remains readable while completion mutation is blocked', function () {
    [$user, , $uom, , $mo, $roll, $cut] = iteration15CutFixture();
    app(CuttingService::class)->recordMarker($cut, [[
        'roll_id' => $roll->id, 'marker_length_m' => 2, 'plies' => 20,
        'qty_fabric_used_m' => 40, 'efficiency_pct' => 90,
    ]], $user);
    $lay = Lay::create([
        'company_id' => 1, 'cut_order_id' => $cut->id, 'lay_no' => $cut->doc_no.'-LEGACY-MIXED',
        'layer_count' => 10, 'lay_date' => now()->toDateString(), 'shade_validation_enabled' => false,
        'status' => 'IN_PROGRESS', 'created_by' => $user->id,
    ]);
    LayRoll::create([
        'company_id' => 1, 'lay_id' => $lay->id, 'fabric_roll_id' => $roll->id,
        'uom_id' => $uom->id, 'qty_used' => 10, 'shade_override' => false, 'created_by' => $user->id,
    ]);
    app(CuttingService::class)->generateBundles($cut, $cut->lines->first()->id, 20, $user);
    $allocationBefore = (float) $mo->materialAllocations()->first()->qty_consumed;
    $result = app(OperationalIntegrityService::class)->inspect($mo, $user);

    expect($result['authority_conflict']['mixed_path'])->toBeTrue()
        ->and($result['authority_conflict']['status'])->toBe('CONFLICT')
        ->and($result['authority_conflict']['historical_mutation'])->toBeFalse()
        ->and($result['lineage']['forward'])->toContain('CONFLICT: Marker + Lay Roll')
        ->and(fn () => app(CuttingService::class)->complete($cut->fresh(), $user))
        ->toThrow(RuntimeException::class, 'DECISION REQUIRED')
        ->and((float) $mo->materialAllocations()->first()->qty_consumed)->toBe($allocationBefore);
});

test('operational matrix keeps qty produced backflush valuation and COGS boundaries blocked', function () {
    [$user, , , , $mo] = iteration15CutFixture();
    $mo->update(['qty_produced' => 100]);
    $result = app(OperationalIntegrityService::class)->inspect($mo->fresh(), $user);
    $matrix = app(OperationalIntegrityService::class)->authorityMatrix(1, $user);

    expect($result['qty_produced']['classification'])->toContain('NOT AUTHORITATIVE')
        ->and($result['qty_produced']['writer'])->toBe('NOT FOUND')
        ->and($result['backflush']['uses_qty_produced'])->toBeTrue()
        ->and($result['backflush']['bypasses_its'])->toBeFalse()
        ->and($result['backflush']['bypasses_reservation'])->toBeFalse()
        ->and($result['backflush']['convergence'])->toContain('BLOCKED')
        ->and($matrix['states']['INVENTORY_AUTHORITY'])->toBe('ITS')
        ->and($matrix['states']['COGS'])->toBe('NOT DEFINED')
        ->and($matrix['states']['HISTORICAL_CONSUMPTION_REWRITE'])->toBe('PROHIBITED')
        ->and($matrix['migration'])->toBe('NONE');
});

test('operational integrity reports deterministic movement and GR posting convergence without writes', function () {
    [$user, , , , $mo] = iteration15CutFixture();
    $beforeMovements = DB::table('stock_movements')->count();
    $beforeJournals = DB::table('journals')->count();
    $result = app(OperationalIntegrityService::class)->inspect($mo, $user);

    expect($result['inventory']['authority'])->toBe('ITS')
        ->and($result['inventory']['duplicate_detected'])->toBeFalse()
        ->and($result['accounting']['accounting_posting_conflict'])->toBe('NOT DETECTED FOR GR POSTING')
        ->and($result['accounting']['production_events'])->toContain('BLOCKED')
        ->and(DB::table('stock_movements')->count())->toBe($beforeMovements)
        ->and(DB::table('journals')->count())->toBe($beforeJournals)
        ->and($result['writes_performed'])->toBeFalse();
});

test('operational integrity enforces company isolation', function () {
    [, , , , $mo] = iteration15CutFixture();
    $company = DB::table('companies')->insertGetId([
        'code' => 'I15-'.uniqid(), 'name' => 'Other', 'base_currency' => 'IDR', 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $other = User::factory()->create(['company_id' => $company]);
    expect(fn () => app(OperationalIntegrityService::class)->inspect($mo, $other))
        ->toThrow(RuntimeException::class, 'akses');
});
