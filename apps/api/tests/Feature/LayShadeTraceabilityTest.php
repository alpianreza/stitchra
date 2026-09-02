<?php

use Illuminate\Support\Facades\DB;
use Modules\Core\Approval\ApprovalEngine;
use Modules\Core\Models\ApprovalFlow;
use Modules\Core\Models\ApprovalFlowStep;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Cutting\Models\CustomerShadeRule;
use Modules\Cutting\Services\CuttingService;
use Modules\Cutting\Services\LayExecutionService;
use Modules\Inventory\Models\StockReservation;
use Modules\MasterData\Models\ShadeGroup;
use Modules\Production\Services\MaterialIssueService;
use Modules\Receiving\Models\FabricRoll;

function layFixture(): array
{
    [$user,$fabric,,,$pcs,$warehouse,$mo,,,$colorway,$size] = shopFixture();
    $rolls = FabricRoll::orderBy('id')->get();
    $sg1 = ShadeGroup::create(['company_id'=>1,'code'=>'SG1-'.uniqid(),'name'=>'SG1']);
    $sg2 = ShadeGroup::create(['company_id'=>1,'code'=>'SG2-'.uniqid(),'name'=>'SG2']);
    $rolls[0]->update(['shade_group_id'=>$sg1->id]);
    $rolls[1]->update(['shade_group_id'=>$sg2->id]);
    StockReservation::withoutGlobalScopes()->firstOrCreate(
        ['company_id'=>1,'mo_id'=>$mo->id,'material_id'=>$fabric->id,'warehouse_id'=>$warehouse->id,'roll_id'=>$rolls[1]->id],
        ['ownership'=>'COMPANY','qty_reserved'=>100,'qty_issued'=>0,'status'=>'ACTIVE','created_by'=>$user->id],
    );
    app(MaterialIssueService::class)->issue($mo,$warehouse->id,[
        ['material_id'=>$fabric->id,'qty'=>100,'roll_id'=>$rolls[0]->id],
        ['material_id'=>$fabric->id,'qty'=>100,'roll_id'=>$rolls[1]->id],
    ],$user);
    $cut = app(CuttingService::class)->create($mo->fresh(),[['colorway_id'=>$colorway->id,'size_id'=>$size->id,'qty_cut'=>100]],$user);
    return [$user,$cut,$rolls,$mo,$sg1,$sg2];
}

it('defaults enabled, rejects NULL/different shade, accepts exact shade, and creates no ledger movement', function () {
    [$user,$cut,$rolls,,$sg1,$sg2] = layFixture();
    $service = app(LayExecutionService::class);
    $lay = $service->createLay($cut,20,$user);
    expect($lay->shade_validation_enabled)->toBeTrue();
    $before = DB::table('stock_ledger')->count();
    $service->addRoll($lay,$rolls[0],50,$user);
    $rolls[1]->update(['shade_group_id'=>null]);
    expect(fn () => $service->addRoll($lay->fresh(),$rolls[1]->fresh(),20,$user))->toThrow(RuntimeException::class,'BR-053 BLOCK');
    $rolls[1]->update(['shade_group_id'=>$sg2->id]);
    expect(fn () => $service->addRoll($lay->fresh(),$rolls[1]->fresh(),20,$user))->toThrow(RuntimeException::class,'BR-053 BLOCK');
    $rolls[1]->update(['shade_group_id'=>$sg1->id]);
    $accepted = $service->addRoll($lay->fresh(),$rolls[1]->fresh(),20,$user);
    expect((float) $accepted->qty_used)->toBe(20.0)
        ->and(DB::table('stock_ledger')->count())->toBe($before);
});

it('buyer can disable shade blocking while output and bundle preserve lineage', function () {
    [$user,$cut,$rolls] = layFixture();
    $customer = $cut->productionOrder->salesOrder->customer;
    CustomerShadeRule::create(['company_id'=>1,'customer_id'=>$customer->id,'enabled'=>false,'created_by'=>$user->id]);
    $service = app(LayExecutionService::class);
    $lay = $service->createLay($cut,10,$user);
    $service->addRoll($lay,$rolls[0],50,$user);
    $service->addRoll($lay->fresh(),$rolls[1],50,$user);
    $output = $service->createOutput($lay->fresh(),$cut->lines->first()->id,100,$user);
    $bundles = $service->generateBundles($output,20,$user);
    $done = $service->completeLay($lay->fresh(),$user);
    expect($bundles)->toHaveCount(5)
        ->and($bundles[0]->cutOutput->lay->rolls)->toHaveCount(2)
        ->and($done->status)->toBe('COMPLETED');
});

it('pending or rejected mismatch override remains blocked', function () {
    [$user,$cut,$rolls] = layFixture();
    $role = Role::withoutGlobalScopes()->firstOrCreate(['company_id'=>1,'code'=>'cut_mgr'],['name'=>'Cut Manager']);
    $flow = ApprovalFlow::create(['company_id'=>1,'doc_type'=>'SHADE_OVERRIDE','version'=>1,'mode'=>'sequential','is_active'=>true]);
    ApprovalFlowStep::create(['flow_id'=>$flow->id,'step_no'=>1,'role_id'=>$role->id]);
    $approver = User::factory()->create(['company_id'=>1]); $approver->roles()->sync([$role->id]);
    $service = app(LayExecutionService::class); $lay = $service->createLay($cut,20,$user);
    $service->addRoll($lay,$rolls[0],50,$user);
    $override = $service->requestOverride($lay->fresh(),$rolls[1],25,'Buyer shade exception',$user);
    expect(fn () => $service->applyOverride($override,$user))->toThrow(RuntimeException::class,'ApprovalEngine APPROVED');
    app(ApprovalEngine::class)->reject($override->approvalRequest,$approver,'Rejected');
    expect(fn () => $service->applyOverride($override->fresh(),$user))->toThrow(RuntimeException::class,'ApprovalEngine APPROVED');
});

it('approved mismatch override adds an audited Lay Roll', function () {
    [$user,$cut,$rolls] = layFixture();
    $role = Role::withoutGlobalScopes()->firstOrCreate(['company_id'=>1,'code'=>'cut_mgr'],['name'=>'Cut Manager']);
    $flow = ApprovalFlow::create(['company_id'=>1,'doc_type'=>'SHADE_OVERRIDE','version'=>1,'mode'=>'sequential','is_active'=>true]);
    ApprovalFlowStep::create(['flow_id'=>$flow->id,'step_no'=>1,'role_id'=>$role->id]);
    $approver = User::factory()->create(['company_id'=>1]); $approver->roles()->sync([$role->id]);
    $service = app(LayExecutionService::class); $lay = $service->createLay($cut,20,$user);
    $service->addRoll($lay,$rolls[0],50,$user);
    $override = $service->requestOverride($lay->fresh(),$rolls[1],25,'Buyer approved exception',$user);
    app(ApprovalEngine::class)->approve($override->approvalRequest,$approver);
    $line = $service->applyOverride($override->fresh(),$user);
    expect($line->shade_override)->toBeTrue()->and($line->fabric_roll_id)->toBe($rolls[1]->id);
});
