<?php

use Modules\Finance\Services\FgActualCostingService;

it('calculates D-09 cost per pcs at four decimals',function(){
    expect(FgActualCostingService::costPerPcs(1000,300))->toBe(3.3333);
});

it('fails closed for zero or negative denominator',function(float $qty){
    FgActualCostingService::costPerPcs(100,$qty);
})->with([0,-1])->throws(RuntimeException::class,'FG received denominator must be greater than zero');

it('uses only company ITS production receipts as the final denominator',function(){
    $source=file_get_contents(app_path('Modules/Finance/Services/FgActualCostingService.php'));
    expect($source)->toContain("where('l.movement_type','PRODUCTION_RECEIPT')")
        ->toContain("where('l.ownership','COMPANY')")
        ->toContain("where('p.production_order_id',$mo->id)")
        ->not->toContain('qty_produced')->not->toContain('PACKED_QTY')->not->toContain('SHIPPED_QTY');
});

it('accounts for all six components with deterministic completeness',function(){
    expect(FgActualCostingService::COMPONENTS)->toBe(['FABRIC','TRIM','LABOR','OVERHEAD','SUBCON','OTHER']);
    $source=file_get_contents(app_path('Modules/Finance/Services/FgActualCostingService.php'));
    foreach(['COMPLETE','NOT_APPLICABLE','MISSING','CONFLICT'] as $status)expect($source)->toContain($status);
    expect($source)->toContain('FAIL_CLOSED: actual-cost completeness');
});

it('integrates the existing freeze and D-06 D-07 variance authority',function(){
    $source=file_get_contents(app_path('Modules/Finance/Services/FgActualCostingService.php'));
    expect($source)->toContain("'ACTUAL_COST_FREEZE'")->toContain('applyFreeze(')
        ->toContain("DB::table('wip_valuation_events')")->toContain("DB::table('fg_valuation_events')")
        ->not->toContain('GlPostingService')->not->toContain('JournalService')->not->toContain('SHIPMENT_COGS');
});

it('migration is additive and performs no backfill',function(){
    $source=file_get_contents(database_path('migrations/2026_09_03_000031_add_d09_fg_actual_costing.php'));
    expect($source)->toContain("Schema::create('fg_actual_costings'")->toContain("Schema::create('fg_actual_costing_components'")
        ->not->toContain('DB::table(')->not->toContain('->update(');
});

it('exposes authorized read calculate detail and finalize routes',function(){
    $routes=file_get_contents(app_path('Modules/Finance/routes/finance.php'));
    expect($routes)->toContain("costing/mo/{productionOrder}/fg-actual")
        ->toContain("costing/fg-actual/{costing}")->toContain("costing/fg-actual/{costing}/finalize")
        ->toContain('permission:costing.actual.view')->toContain('permission:finance.journal.create')->toContain('permission:finance.journal.approve');
});
