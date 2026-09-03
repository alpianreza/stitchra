<?php

use Illuminate\Support\Facades\DB;
use Modules\Finance\Services\ManufacturingValuationService;
use Modules\Production\Services\NamedProductionMeasureService;

it('preserves the seven D-03 named measures and adds no generic output', function () {
    expect(NamedProductionMeasureService::NAMED_MEASURES)->toBe([
        'CUT_OUTPUT','SEWING_FINAL_OUT','FINISHING_OUT','QC_FINAL_PASS','PACKED_QTY','FG_RECEIVED_QTY','SHIPPED_QTY',
    ])->and(ManufacturingValuationService::POLICY_VERSION)->toBe('D06_D07_V1');
});

it('locks D-06 valuation to explicit transfer boundaries and six components', function () {
    expect(ManufacturingValuationService::BOUNDARIES)->toBe(['CUTTING_TO_SEWING','SEWING_TO_FINISHING'])
        ->and(ManufacturingValuationService::COMPONENTS)->toBe(['FABRIC','TRIM','LABOR','OVERHEAD','SUBCON','OTHER']);
});

it('migration is additive and contains no historical backfill', function () {
    $source=file_get_contents(database_path('migrations/2026_09_03_000030_add_d06_d07_manufacturing_valuation.php'));
    expect($source)->toContain("Schema::create('wip_valuation_events'")
        ->toContain("Schema::create('fg_valuation_events'")
        ->toContain("Schema::create('actual_cost_freezes'")
        ->toContain("Schema::create('valuation_adjustments'")
        ->not->toContain('DB::table(')
        ->not->toContain('->update(');
});

it('does not add inventory movement types or D-08 D-10 posting behavior', function () {
    $service=file_get_contents(app_path('Modules/Finance/Services/ManufacturingValuationService.php'));
    expect($service)->not->toContain("'WIP_VALUATION'")
        ->not->toContain("'FG_REVALUATION'")
        ->not->toContain('SHIPMENT_COGS')
        ->not->toContain('GlPostingService')
        ->not->toContain('JournalService')
        ->not->toContain('qty_produced');
});

it('uses deterministic identities and explicit fail-closed boundaries', function () {
    $service=file_get_contents(app_path('Modules/Finance/Services/ManufacturingValuationService.php'));
    expect($service)->toContain("where('source_type',$sourceType)")
        ->toContain("where('source_id',$sourceId)")
        ->toContain("where('valuation_stage',$stage)")
        ->toContain("where('component',$component)")
        ->toContain('FAIL_CLOSED')
        ->toContain('CONFLICT: WIP valuation identity')
        ->toContain('CONFLICT: FG valuation identity')
        ->toContain('CONFLICT: variance identity');
});

it('serializes FG valuation and freeze workflows around the MO', function () {
    $workflow=file_get_contents(app_path('Modules/Finance/Services/ManufacturingValuationWorkflow.php'));
    expect($workflow)->toContain('lockForUpdate()')
        ->toContain('VALUED_REPLAY')
        ->toContain('all authoritative FG receipts must be valued')
        ->toContain('pending actual-cost freeze has different source evidence');
});

it('keeps valuation tables separate from inventory balances', function () {
    $migration=file_get_contents(database_path('migrations/2026_09_03_000030_add_d06_d07_manufacturing_valuation.php'));
    expect($migration)->not->toContain("Schema::table('stock_ledger'")
        ->not->toContain("Schema::table('stock_balances'")
        ->not->toContain("Schema::table('stock_movements'");
});

it('defines no historical data during migration execution', function () {
    expect(DB::table('wip_valuation_events')->count())->toBe(0)
        ->and(DB::table('fg_valuation_events')->count())->toBe(0)
        ->and(DB::table('actual_cost_freezes')->count())->toBe(0)
        ->and(DB::table('valuation_adjustments')->count())->toBe(0);
});
