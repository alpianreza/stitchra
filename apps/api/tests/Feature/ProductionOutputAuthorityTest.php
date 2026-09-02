<?php

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Finance\Models\Journal;
use Modules\Packing\Services\PackingService;
use Modules\Production\Services\ProductionOutputAuthorityService;
use Modules\Qc\Services\QcService;

function iteration13PackedFixture(float $qty = 100): array
{
    [$user, , $style, $so, $mo, $colorway, $size, $fg] = packFixture($qty);
    $qcService = app(QcService::class);
    $qc = $qcService->finalize($qcService->create($mo, 'FINAL', $qty, $user), $user);
    $packingService = app(PackingService::class);
    $packing = $packingService->create($so, $mo->id, $user);
    $packingService->addCarton($packing, [], [[
        'style_id' => $style->id, 'colorway_id' => $colorway->id,
        'size_id' => $size->id, 'qty' => $qty,
    ]], $user);
    $packing = $packingService->finalize($packing->fresh(), $fg->id, $user);

    return [$user, $mo->fresh(), $qc->fresh(), $packing->fresh()];
}

test('legacy qty_produced is reported but never promoted to output or completion authority', function () {
    [$user, , , , $mo] = qcFixture(75);
    $result = app(ProductionOutputAuthorityService::class)->inspect($mo, $user);

    expect($result['qty_produced']['stored_value'])->toBe(75.0)
        ->and($result['qty_produced']['status'])->toBe('LEGACY')
        ->and($result['qty_produced']['authoritative'])->toBeFalse()
        ->and($result['production_output_authority']['status'])->toBe('NOT DEFINED')
        ->and($result['production_output_authority']['authoritative_qty'])->toBeNull()
        ->and($result['production_completion']['status'])->toBe('NOT DEFINED')
        ->and($result['production_completion']['completion_endpoint'])->toBeNull()
        ->and($result['writes_performed'])->toBeFalse()
        ->and($result['migration'])->toBe('NONE');
});

test('stage authorities retain their narrow scope across QC Packing and FG receipt', function () {
    [$user, $mo] = iteration13PackedFixture(100);
    $beforeLedger = DB::table('stock_ledger')->count();
    $beforeScans = DB::table('production_scans')->count();
    $beforeJournals = Journal::withoutGlobalScopes()->count();

    $result = app(ProductionOutputAuthorityService::class)->inspect($mo, $user);
    $matrix = collect($result['candidate_matrix'])->keyBy('candidate_source');

    expect($matrix['QC FINAL PASS']['quantity'])->toBe(100.0)
        ->and($matrix['QC FINAL PASS']['status'])->toBe('DEFINED')
        ->and($matrix['QC FINAL PASS']['existing_authority'])->toContain('Packing eligibility')
        ->and($matrix['Packing']['quantity'])->toBe(100.0)
        ->and($matrix['Packing']['status'])->toBe('DERIVED')
        ->and($matrix['PRODUCTION_RECEIPT']['quantity'])->toBe(100.0)
        ->and($matrix['PRODUCTION_RECEIPT']['status'])->toBe('DEFINED')
        ->and($result['production_output_authority']['status'])->toBe('NOT DEFINED')
        ->and($result['boundaries']['fg'])->toContain('FG_VALUATION = NOT DEFINED')
        ->and($result['boundaries']['actual_cost'])->toContain('COST_PER_UNIT = NOT DEFINED')
        ->and(DB::table('stock_ledger')->count())->toBe($beforeLedger)
        ->and(DB::table('production_scans')->count())->toBe($beforeScans)
        ->and(Journal::withoutGlobalScopes()->count())->toBe($beforeJournals);
});

test('undefined partial production and defect arithmetic stay explicit and blocked', function () {
    [$user, , , , $mo] = qcFixture(40);
    $result = app(ProductionOutputAuthorityService::class)->inspect($mo, $user);

    expect($result['partial_production']['status'])->toBe('NOT DEFINED')
        ->and($result['partial_production']['reason'])->toContain('PARTIAL_PRODUCTION_RULE = NOT DEFINED')
        ->and($result['defect_rework_scrap']['status'])->toBe('NOT DEFINED')
        ->and($result['defect_rework_scrap']['reason'])->toContain('DEFECT_OUTPUT_ARITHMETIC = NOT DEFINED')
        ->and($result['lineage']['forward'])->toContain('Cut Output')
        ->and($result['lineage']['reverse'])->toContain('PRODUCTION_RECEIPT')
        ->and($result['lineage']['authority_boundary'])->toBe('Production Output Authority = NOT DEFINED; no single source is fabricated.');
});

test('production output authority inspection enforces company isolation', function () {
    [, , , , $mo] = qcFixture(20);
    $otherCompany = DB::table('companies')->insertGetId([
        'code' => 'I13-'.uniqid(), 'name' => 'Other', 'base_currency' => 'IDR', 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $other = User::factory()->create(['company_id' => $otherCompany]);

    expect(fn () => app(ProductionOutputAuthorityService::class)->inspect($mo, $other))
        ->toThrow(RuntimeException::class, 'akses');
});
