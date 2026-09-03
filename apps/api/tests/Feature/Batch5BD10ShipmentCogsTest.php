<?php

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Finance\Models\AccountMapping;
use Modules\Finance\Models\GlPeriod;
use Modules\Finance\Models\Journal;
use Modules\Finance\Services\ShipmentCogsService;
use Modules\Inventory\Models\StockBalance;
use Modules\MasterData\Models\ChartOfAccount;
use Modules\Shipping\Services\ShipmentInventoryValuationService;
use Modules\Shipping\Services\ShipmentService;

function d10Fixture(float $cost=10,string $date='2026-09-03'):array
{
    [$user,,,$so,$mo,$colorway,$size,$fg,$pl]=approvedFgFixture();
    StockBalance::withoutGlobalScopes()->where('company_id',1)->where('item_type','FG')->where('warehouse_id',$fg->id)
        ->where('style_id',$mo->style_id)->where('colorway_id',$colorway->id)->where('size_id',$size->id)->update(['avg_cost'=>$cost]);
    $shipment=app(ShipmentService::class)->create($pl,['ship_date'=>$date],$user);
    app(ShipmentInventoryValuationService::class)->ship($shipment,$fg->id,$user);
    $cogs=ChartOfAccount::create(['company_id'=>1,'code'=>'COGS-'.uniqid(),'name'=>'COGS','type'=>'EXPENSE','normal_balance'=>'DEBIT']);
    $inventory=ChartOfAccount::create(['company_id'=>1,'code'=>'FG-'.uniqid(),'name'=>'FG Inventory','type'=>'ASSET','normal_balance'=>'DEBIT']);
    AccountMapping::create(['company_id'=>1,'event'=>'SHIPMENT_COGS','debit_account_id'=>$cogs->id,'credit_account_id'=>$inventory->id]);
    return[$user,$shipment->fresh(),$cogs,$inventory];
}

it('posts Base COGS exactly from D-08 without multiplying quantity again',function(){
    [$user,$shipment,$debit,$credit]=d10Fixture(12.345678);$result=app(ShipmentCogsService::class)->post($shipment,$user);$c=$result['cogs'];$d08=$c->lines->first()->d08()->first();
    expect((float)$c->base_cogs_total)->toBe((float)$d08->shipment_inventory_cost)
        ->and($c->journal->event)->toBe('SHIPMENT_COGS')->and($c->journal->journal_date->toDateString())->toBe($shipment->ship_date->toDateString())
        ->and((float)$c->journal->total_debit)->toBe((float)$d08->shipment_inventory_cost)
        ->and($c->journal->lines->where('coa_id',$debit->id)->sum('debit'))->toBe((float)$d08->shipment_inventory_cost)
        ->and($c->journal->lines->where('coa_id',$credit->id)->sum('credit'))->toBe((float)$d08->shipment_inventory_cost);
});

it('returns the same journal on replay and prevents duplicate posting',function(){
    [$user,$shipment]=d10Fixture();$service=app(ShipmentCogsService::class);$a=$service->post($shipment,$user);$b=$service->post($shipment,$user);
    expect($a['created'])->toBeTrue()->and($b['created'])->toBeFalse()->and($b['cogs']->journal_id)->toBe($a['cogs']->journal_id)
        ->and(Journal::withoutGlobalScopes()->where('event','SHIPMENT_COGS')->count())->toBe(1);
});

it('fails closed for a SHIPPED Shipment without D-08',function(){
    [$user,,,$so,$mo,$colorway,$size,$fg,$pl]=approvedFgFixture();
    $shipment=app(ShipmentService::class)->create($pl,['ship_date'=>'2026-09-03'],$user);
    app(ShipmentService::class)->ship($shipment,$fg->id,$user);
    expect(fn()=>app(ShipmentCogsService::class)->post($shipment->fresh(),$user))->toThrow(RuntimeException::class,'D-08 valuation');
});

it('fails closed without SHIPMENT_COGS account mapping',function(){
    [$user,,,$so,$mo,$colorway,$size,$fg,$pl]=approvedFgFixture();
    StockBalance::withoutGlobalScopes()->where('company_id',1)->where('item_type','FG')->where('warehouse_id',$fg->id)
        ->where('style_id',$mo->style_id)->where('colorway_id',$colorway->id)->where('size_id',$size->id)->update(['avg_cost'=>5]);
    $shipment=app(ShipmentService::class)->create($pl,['ship_date'=>'2026-09-03'],$user);
    app(ShipmentInventoryValuationService::class)->ship($shipment,$fg->id,$user);
    expect(fn()=>app(ShipmentCogsService::class)->post($shipment,$user))->toThrow(RuntimeException::class,'mapping');
});

it('rejects D-08 amount that conflicts with its valued ITS Shipment ledger',function(){
    [$user,$shipment]=d10Fixture();
    DB::table('shipment_inventory_valuations')->where('shipment_id',$shipment->id)->increment('shipment_inventory_cost',1);
    expect(fn()=>app(ShipmentCogsService::class)->post($shipment,$user))->toThrow(RuntimeException::class,'evidence conflicts');
});

it('rejects cross-company callers and source access',function(){
    [$user,$shipment]=d10Fixture();
    $company=DB::table('companies')->insertGetId(['code'=>'D10-OTHER-'.uniqid(),'name'=>'Other D10','base_currency'=>'IDR','is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
    $other=User::factory()->create(['company_id'=>$company]);
    expect(fn()=>app(ShipmentCogsService::class)->post($shipment,$other))->toThrow(RuntimeException::class,'access');
});

it('conflicts when mapped accounting source changes after immutable posting',function(){
    [$user,$shipment]=d10Fixture();$service=app(ShipmentCogsService::class);$service->post($shipment,$user);
    $debit=ChartOfAccount::create(['company_id'=>1,'code'=>'COGS2-'.uniqid(),'name'=>'COGS 2','type'=>'EXPENSE','normal_balance'=>'DEBIT']);
    $credit=ChartOfAccount::create(['company_id'=>1,'code'=>'FG2-'.uniqid(),'name'=>'FG Inventory 2','type'=>'ASSET','normal_balance'=>'DEBIT']);
    AccountMapping::withoutGlobalScopes()->where('company_id',1)->where('event','SHIPMENT_COGS')->update(['debit_account_id'=>$debit->id,'credit_account_id'=>$credit->id]);
    expect(fn()=>$service->post($shipment,$user))->toThrow(RuntimeException::class,'IDEMPOTENCY CONFLICT');
});

it('does not move a closed Shipment period',function(){
    [$user,$shipment]=d10Fixture(10,'2026-08-31');GlPeriod::withoutGlobalScopes()->updateOrCreate(['company_id'=>1,'period'=>'2026-08'],['status'=>'CLOSED']);
    expect(fn()=>app(ShipmentCogsService::class)->post($shipment,$user))->toThrow(RuntimeException::class,'CLOSED');
    expect(Journal::withoutGlobalScopes()->where('event','SHIPMENT_COGS')->count())->toBe(0);
});

it('recognizes legitimate zero D-08 without illegal zero journal lines',function(){
    [$user,$shipment]=d10Fixture(0);$r=app(ShipmentCogsService::class)->post($shipment,$user)['cogs'];
    expect($r->status)->toBe('ZERO_COST')->and((float)$r->base_cogs_total)->toBe(0.0)->and($r->journal_id)->toBeNull();
});

it('keeps D-07 D-09 and D-11 outside D-10 source code',function(){
    $source=file_get_contents(app_path('Modules/Finance/Services/ShipmentCogsService.php'));
    expect($source)->toContain("self::EVENT")->toContain('shipment_inventory_cost')->not->toContain('FgActualCosting')
        ->not->toContain('ManufacturingValuation')->not->toContain('reverseIntoPeriod')->not->toContain('qty_produced');
    $migration=file_get_contents(database_path('migrations/2026_09_03_000033_add_d10_shipment_cogs.php'));
    expect($migration)->not->toContain('DB::table(')->not->toContain('->update(');
});
