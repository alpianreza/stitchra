<?php

use Modules\Core\Models\Company;
use Modules\Core\Support\CurrentCompany;
use Modules\Cutting\Models\Bundle;
use Modules\Cutting\Services\CuttingService;
use Modules\Cutting\Services\LayExecutionService;
use Modules\MasterData\Models\ShadeGroup;
use Modules\Production\Services\MaterialIssueService;
use Modules\Receiving\Models\FabricRoll;
use Modules\ShopFloor\Models\WipTransfer;
use Modules\ShopFloor\Services\ScanService;

function sewingBundleFixture(): array
{
    $fixture = shopFixture();
    [$user,$fabric,,,$pcs,$warehouse,$mo,$op1,$op2,$colorway,$size] = $fixture;
    $roll = FabricRoll::where('roll_no','R001')->firstOrFail();
    $shade = ShadeGroup::create(['company_id'=>1,'code'=>'SEW-'.uniqid(),'name'=>'Sewing shade']);
    $roll->update(['shade_group_id'=>$shade->id]);
    app(MaterialIssueService::class)->issue($mo,$warehouse->id,[
        ['material_id'=>$fabric->id,'qty'=>100,'roll_id'=>$roll->id],
    ],$user);
    $cut = app(CuttingService::class)->create($mo->fresh(),[
        ['colorway_id'=>$colorway->id,'size_id'=>$size->id,'qty_cut'=>100],
    ],$user);
    $layService = app(LayExecutionService::class);
    $lay = $layService->createLay($cut,10,$user);
    $layService->addRoll($lay,$roll,100,$user);
    $output = $layService->createOutput($lay->fresh(),$cut->lines->first()->id,100,$user);
    $bundles = $layService->generateBundles($output,20,$user);
    $layService->completeLay($lay->fresh(),$user);

    return [$user,$mo,$op1,$op2,$bundles[0],$output,$lay,$roll];
}

function finishingBundleFixture(): array
{
    [$user,$mo,$op1,$op2,$bundle,$output,$lay,$roll] = sewingBundleFixture();
    $service = app(ScanService::class);
    $service->scan(1,$bundle->bundle_no,['operation_id'=>$op1->id,'direction'=>'IN','stage'=>'SEWING'],$user);
    $service->scan(1,$bundle->bundle_no,['operation_id'=>$op1->id,'direction'=>'OUT','stage'=>'SEWING'],$user);
    $service->scan(1,$bundle->bundle_no,['operation_id'=>$op2->id,'direction'=>'IN','stage'=>'SEWING'],$user);
    $service->scan(1,$bundle->bundle_no,['operation_id'=>$op2->id,'direction'=>'OUT','stage'=>'SEWING'],$user);

    return [$user,$mo,$op1,$op2,$bundle->fresh(),$output,$lay,$roll];
}

it('moves a completed cut-output bundle into sewing with quantity snapshot and lineage', function () {
    [$user,, $op1,, $bundle,$output,$lay,$roll] = sewingBundleFixture();
    $service = app(ScanService::class);
    $input = $service->scan(1,$bundle->bundle_no,['operation_id'=>$op1->id,'direction'=>'IN','stage'=>'SEWING'],$user);
    $lineage = $service->lineage(1,$bundle->bundle_no);

    expect((float)$input->qty)->toBe((float)$bundle->qty)
        ->and(WipTransfer::where('bundle_id',$bundle->id)->where('from_stage','CUTTING')->where('to_stage','SEWING')->count())->toBe(1)
        ->and($lineage['bundle']->cutOutput->id)->toBe($output->id)
        ->and($lineage['bundle']->cutOutput->lay->id)->toBe($lay->id)
        ->and($lineage['bundle']->cutOutput->lay->rolls->first()->fabricRoll->id)->toBe($roll->id);
});

it('prevents double consumption and creates sewing output WIP only after the final operation', function () {
    [$user,, $op1,$op2,$bundle] = sewingBundleFixture();
    $service = app(ScanService::class);
    $service->scan(1,$bundle->bundle_no,['operation_id'=>$op1->id,'direction'=>'IN','stage'=>'SEWING'],$user);
    expect(fn () => $service->scan(1,$bundle->bundle_no,['operation_id'=>$op1->id,'direction'=>'IN','stage'=>'SEWING'],$user))
        ->toThrow(RuntimeException::class,'duplicate IN');
    $service->scan(1,$bundle->bundle_no,['operation_id'=>$op1->id,'direction'=>'OUT','stage'=>'SEWING'],$user);
    $service->scan(1,$bundle->bundle_no,['operation_id'=>$op2->id,'direction'=>'IN','stage'=>'SEWING'],$user);
    $output = $service->scan(1,$bundle->bundle_no,['operation_id'=>$op2->id,'direction'=>'OUT','stage'=>'SEWING'],$user);

    expect((float)$output->qty)->toBe((float)$bundle->qty)
        ->and($bundle->fresh()->current_stage)->toBe('FINISHING')
        ->and(WipTransfer::where('bundle_id',$bundle->id)->where('from_stage','SEWING')->where('to_stage','FINISHING')->count())->toBe(1);
});

it('accepts finishing input only from sewing WIP and traces finishing output', function () {
    [$user,, $op1,, $bundle] = finishingBundleFixture();
    $service = app(ScanService::class);
    $eligible = collect($service->eligibleFinishingBundles(1))->firstWhere('bundle_no',$bundle->bundle_no);
    $input = $service->scan(1,$bundle->bundle_no,['operation_id'=>$op1->id,'direction'=>'IN','stage'=>'FINISHING'],$user);
    $output = $service->scan(1,$bundle->bundle_no,['operation_id'=>$op1->id,'direction'=>'OUT','stage'=>'FINISHING'],$user);
    $lineage = $service->lineage(1,$bundle->bundle_no);

    expect($eligible['source_wip_transfer_id'])->not->toBeNull()
        ->and((float)$eligible['available_qty'])->toBe((float)$bundle->qty)
        ->and((float)$input->qty)->toBe((float)$bundle->qty)
        ->and((float)$output->qty)->toBe((float)$input->qty)
        ->and($lineage['finishing_source']->to_stage)->toBe('FINISHING')
        ->and($lineage['finishing_inputs'])->toHaveCount(1)
        ->and($lineage['finishing_outputs'])->toHaveCount(1)
        ->and($lineage['packing_boundary']['direct_bundle_to_carton_defined'])->toBeFalse();
});

it('rejects finishing without valid sewing WIP and duplicate finishing input', function () {
    [$user,, $op1,, $bundle] = sewingBundleFixture();
    $service = app(ScanService::class);
    $bundle->update(['current_stage'=>'FINISHING']);
    expect(fn () => $service->scan(1,$bundle->bundle_no,['operation_id'=>$op1->id,'direction'=>'IN','stage'=>'FINISHING'],$user))
        ->toThrow(RuntimeException::class,'source WIP transfer');

    [$user2,, $finishingOp,, $ready] = finishingBundleFixture();
    $service->scan(1,$ready->bundle_no,['operation_id'=>$finishingOp->id,'direction'=>'IN','stage'=>'FINISHING'],$user2);
    expect(fn () => $service->scan(1,$ready->bundle_no,['operation_id'=>$finishingOp->id,'direction'=>'IN','stage'=>'FINISHING'],$user2))
        ->toThrow(RuntimeException::class,'duplicate IN');
});

it('rejects backward finishing operation sequence', function () {
    [$user,, $op1,$op2,$bundle] = finishingBundleFixture();
    $service = app(ScanService::class);
    $service->scan(1,$bundle->bundle_no,['operation_id'=>$op2->id,'direction'=>'IN','stage'=>'FINISHING'],$user);
    $service->scan(1,$bundle->bundle_no,['operation_id'=>$op2->id,'direction'=>'OUT','stage'=>'FINISHING'],$user);
    expect(fn () => $service->scan(1,$bundle->bundle_no,['operation_id'=>$op1->id,'direction'=>'IN','stage'=>'FINISHING'],$user))
        ->toThrow(RuntimeException::class,'harus maju');
});

it('rejects a traced bundle whose parent lay is not completed', function () {
    [$user,, $op1,, $bundle,, $lay] = sewingBundleFixture();
    $lay->update(['status'=>'IN_PROGRESS']);

    expect(fn () => app(ScanService::class)->scan(1,$bundle->bundle_no,['operation_id'=>$op1->id,'direction'=>'IN','stage'=>'SEWING'],$user))
        ->toThrow(RuntimeException::class,'parent Lay belum COMPLETED');
});

it('keeps historical bundles without cut output readable and eligible', function () {
    $fixture = shopFixture();
    [$user,,,,,,$mo,$op1,, $colorway,$size] = $fixture;
    $cutting = app(CuttingService::class);
    $cut = $cutting->create($mo,[['colorway_id'=>$colorway->id,'size_id'=>$size->id,'qty_cut'=>10]],$user);
    $bundles = $cutting->generateBundles($cut,$cut->lines->first()->id,10,$user);
    $service = app(ScanService::class);
    $eligible = collect($service->eligibleBundles(1,$mo->id))->firstWhere('bundle_no',$bundles[0]->bundle_no);

    expect($eligible['lineage_complete'])->toBeFalse();
    $service->scan(1,$bundles[0]->bundle_no,['operation_id'=>$op1->id,'direction'=>'IN','stage'=>'SEWING'],$user);
});

it('does not expose or process a bundle from another company', function () {
    [$user,$mo,$op1,, $bundle] = sewingBundleFixture();
    $other = Company::factory()->create();
    CurrentCompany::set($other->id);
    $service = app(ScanService::class);

    expect($service->eligibleBundles($other->id,$mo->id))->toBe([])
        ->and($service->eligibleFinishingBundles($other->id,$mo->id))->toBe([])
        ->and(fn () => $service->scan($other->id,$bundle->bundle_no,['operation_id'=>$op1->id,'direction'=>'IN','stage'=>'SEWING'],$user))
        ->toThrow(RuntimeException::class);
});
