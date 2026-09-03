<?php

use Illuminate\Support\Facades\DB;
use Modules\Cutting\Services\CuttingService;
use Modules\Cutting\Services\LayExecutionService;
use Modules\MasterData\Models\Customer;
use Modules\MasterData\Models\ShadeGroup;
use Modules\Packing\Services\PackingService;
use Modules\Production\Services\MaterialIssueService;
use Modules\Production\Services\NamedProductionMeasureService;
use Modules\Qc\Models\QcInspection;
use Modules\Receiving\Models\FabricRoll;

function batch1QcCycle($user, Customer $customer, $mo, int $cycle, string $verdict, float $qty): QcInspection
{
    return QcInspection::create([
        'company_id' => $mo->company_id,
        'doc_no' => 'QC-B1-'.uniqid(),
        'production_order_id' => $mo->id,
        'stage' => 'FINAL',
        'customer_id' => $customer->id,
        'inspection_level' => 'G2',
        'aql_major' => 2.5,
        'aql_minor' => 4,
        'aql_critical' => 0,
        'lot_qty' => $qty,
        'sample_size' => 1,
        'accept_major' => 0,
        'reject_major' => 1,
        'defects_major' => 0,
        'defects_minor' => 0,
        'defects_critical' => 0,
        'cycle' => $cycle,
        'verdict' => $verdict,
        'created_by' => $user->id,
    ]);
}

function batch1BackflushQcSource($user, $mo, string $verdict = 'PASS', float $qty = 100): QcInspection
{
    $customer = Customer::withoutGlobalScopes()->whereKey($mo->salesOrder->customer_id)->firstOrFail();
    return batch1QcCycle($user, $customer, $mo, 1, $verdict, $qty);
}

test('BR-065 latest FINAL cycle non PASS makes QC_FINAL_PASS unavailable', function () {
    [$user, $customer, , , $mo] = qcFixture(100);
    batch1QcCycle($user, $customer, $mo, 1, 'PASS', 100);
    batch1QcCycle($user, $customer, $mo, 2, 'FAIL', 80);

    $measure = app(NamedProductionMeasureService::class)->measure($mo->fresh(), 'QC_FINAL_PASS');

    expect($measure['status'])->toBe('NOT_AVAILABLE')
        ->and($measure['qty'])->toBeNull()
        ->and($measure['source']['cycle'])->toBe(2)
        ->and($measure['source']['verdict'])->toBe('FAIL');
});

test('BR-065 latest FINAL cycle PASS supplies QC_FINAL_PASS', function () {
    [$user, $customer, , , $mo] = qcFixture(100);
    batch1QcCycle($user, $customer, $mo, 1, 'FAIL', 100);
    $latest = batch1QcCycle($user, $customer, $mo, 2, 'PASS', 80);

    $measure = app(NamedProductionMeasureService::class)->measure($mo->fresh(), 'QC_FINAL_PASS');

    expect($measure['status'])->toBe('DEFINED')
        ->and($measure['qty'])->toBe(80.0)
        ->and($measure['source']['qc_inspection_id'])->toBe($latest->id)
        ->and($measure['source']['cycle'])->toBe(2);
});

test('BR-080 Packing rejects stale PASS when latest FINAL cycle is not PASS', function () {
    [$user, $customer, $style, $so, $mo, $colorway, $size] = qcFixture(100);
    $mo->update(['status' => 'QC']);
    batch1QcCycle($user, $customer, $mo, 1, 'PASS', 100);
    batch1QcCycle($user, $customer, $mo, 2, 'FAIL', 80);

    $service = app(PackingService::class);
    $packing = $service->create($so, $mo->id, $user);

    expect($packing->qc_inspection_id)->toBeNull()
        ->and($service->eligiblePackingInputs((int) $mo->company_id))->toBe([])
        ->and(fn () => $service->addCarton($packing, [], [[
            'style_id' => $style->id,
            'colorway_id' => $colorway->id,
            'size_id' => $size->id,
            'qty' => 10,
        ]], $user))->toThrow(RuntimeException::class, 'cycle QC FINAL terbaru bukan PASS');
});

test('BR-066 Backflush QC_FINAL_PASS does not use stale PASS', function () {
    [$user, , $trim, , , $warehouse, $mo] = shopFixture();
    $mo->materialAllocations()->where('material_id', $trim->id)->update(['backflush_stage' => 'QC_FINAL_PASS']);
    $customer = Customer::withoutGlobalScopes()->whereKey($mo->salesOrder->customer_id)->firstOrFail();
    batch1QcCycle($user, $customer, $mo, 1, 'PASS', 100);
    batch1QcCycle($user, $customer, $mo, 2, 'FAIL', 80);
    $before = DB::table('material_issues')->where('production_order_id', $mo->id)->count();

    expect(fn () => app(MaterialIssueService::class)->backflush($mo->fresh(), $warehouse->id, $user))
        ->toThrow(RuntimeException::class, 'Named Stage QC_FINAL_PASS belum memiliki source authority')
        ->and(DB::table('material_issues')->where('production_order_id', $mo->id)->count())->toBe($before);
});

test('BR-066 rejects ACTUAL then BACKFLUSH overlap for the same MO material', function () {
    [$user, , $trim, , $pcs, $warehouse, $mo] = shopFixture();
    $allocation = $mo->materialAllocations()->where('material_id', $trim->id)->firstOrFail();
    $allocation->update(['is_backflush' => false, 'backflush_stage' => null]);
    app(MaterialIssueService::class)->issue($mo->fresh(), $warehouse->id, [[
        'material_id' => $trim->id,
        'qty' => 5,
        'uom_id' => $pcs->id,
    ]], $user);
    $allocation->fresh()->update(['is_backflush' => true, 'backflush_stage' => 'QC_FINAL_PASS']);
    batch1BackflushQcSource($user, $mo);

    expect(fn () => app(MaterialIssueService::class)->backflush($mo->fresh(), $warehouse->id, $user))
        ->toThrow(RuntimeException::class, 'ACTUAL dan BACKFLUSH overlap');
});

test('BR-066 rejects BACKFLUSH then ACTUAL overlap for the same MO material', function () {
    [$user, , $trim, , $pcs, $warehouse, $mo] = shopFixture();
    $allocation = $mo->materialAllocations()->where('material_id', $trim->id)->firstOrFail();
    $allocation->update(['backflush_stage' => 'QC_FINAL_PASS']);
    batch1BackflushQcSource($user, $mo);
    app(MaterialIssueService::class)->backflush($mo->fresh(), $warehouse->id, $user);
    $allocation->fresh()->update(['is_backflush' => false, 'backflush_stage' => null]);

    expect(fn () => app(MaterialIssueService::class)->issue($mo->fresh(), $warehouse->id, [[
        'material_id' => $trim->id,
        'qty' => 1,
        'uom_id' => $pcs->id,
    ]], $user))->toThrow(RuntimeException::class, 'material BACKFLUSH');
});

test('BR-066 serialized duplicate Backflush retry creates one issue and movement', function () {
    [$user, , $trim, , , $warehouse, $mo] = shopFixture();
    $mo->materialAllocations()->where('material_id', $trim->id)->update(['backflush_stage' => 'QC_FINAL_PASS']);
    batch1BackflushQcSource($user, $mo);

    $first = app(MaterialIssueService::class)->backflush($mo->fresh(), $warehouse->id, $user);
    $second = app(MaterialIssueService::class)->backflush($mo->fresh(), $warehouse->id, $user);

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and(DB::table('material_issues')->where('production_order_id', $mo->id)->where('mode', 'BACKFLUSH')->count())->toBe(1)
        ->and(DB::table('stock_movements')->where('movement_type', 'MATERIAL_ISSUE')
            ->where('source_document_type', 'material_issues')->where('source_document_id', $first->id)->count())->toBe(1);
});

test('BR-066 Backflush concurrency guard locks MO before cumulative delta', function () {
    $source = file_get_contents(app_path('Modules/Production/Services/MaterialIssueService.php'));

    expect($source)->toContain("whereKey(\$mo->id)->lockForUpdate()->firstOrFail()")
        ->and($source)->toContain("where('is_backflush', true)->lockForUpdate()->get()")
        ->and($source)->toContain("orderBy('id')->lockForUpdate()->get()");
});

test('BR-031 derived actual consumption excludes unfinished Lay evidence', function () {
    [$user, $fabric, , $uom, , $warehouse, $mo, , , $colorway, $size] = shopFixture();
    $roll = FabricRoll::where('roll_no', 'R001')->firstOrFail();
    app(MaterialIssueService::class)->issue($mo, $warehouse->id, [[
        'material_id' => $fabric->id,
        'qty' => 100,
        'uom_id' => $uom->id,
        'roll_id' => $roll->id,
    ]], $user);
    $shade = ShadeGroup::create(['company_id' => 1, 'code' => 'B1-'.uniqid(), 'name' => 'Batch 1']);
    $roll->update(['shade_group_id' => $shade->id]);
    $cut = app(CuttingService::class)->create($mo->fresh(), [[
        'colorway_id' => $colorway->id,
        'size_id' => $size->id,
        'qty_cut' => 100,
    ]], $user);
    $lays = app(LayExecutionService::class);
    $completed = $lays->createLay($cut, 10, $user);
    $unfinished = $lays->createLay($cut, 10, $user);
    $lays->addRoll($completed, $roll->fresh(), 50, $user);
    $lays->addRoll($unfinished, $roll->fresh(), 50, $user);
    $completedOutput = $lays->createOutput($completed->fresh(), $cut->lines->first()->id, 50, $user);
    $unfinishedOutput = $lays->createOutput($unfinished->fresh(), $cut->lines->first()->id, 50, $user);
    $lays->generateBundles($completedOutput, 10, $user);
    $lays->generateBundles($unfinishedOutput, 10, $user);
    $lays->completeLay($completed->fresh(), $user);

    $allocation = $mo->materialAllocations()->where('material_id', $fabric->id)->firstOrFail();
    expect((float) $allocation->qty_consumed)->toBe(50.0)
        ->and((float) $allocation->actual_consumption_per_pcs)->toBe(1.0)
        ->and($unfinished->fresh()->status)->toBe('IN_PROGRESS');
});
