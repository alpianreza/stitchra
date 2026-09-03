<?php

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\StockBalance;
use Modules\Shipping\Services\ShipmentInventoryValuationService;
use Modules\Shipping\Services\ShipmentService;

it('captures prevailing FG moving average before ITS Shipment OUT',function(){
    [$user,,,$so,$mo,$colorway,$size,$fg,$pl]=approvedFgFixture();
    $balance=StockBalance::withoutGlobalScopes()->where('company_id',1)->where('item_type','FG')->where('warehouse_id',$fg->id)
        ->where('style_id',$mo->style_id)->where('colorway_id',$colorway->id)->where('size_id',$size->id)->firstOrFail();
    $balance->update(['avg_cost'=>12.345678]);
    $shipment=app(ShipmentService::class)->create($pl,['ship_date'=>now()->toDateString()],$user);
    app(ShipmentInventoryValuationService::class)->ship($shipment,$fg->id,$user);
    $value=DB::table('shipment_inventory_valuations')->where('shipment_id',$shipment->id)->first();
    expect((float)$value->moving_average_unit_cost)->toBe(12.345678)
        ->and((float)$value->shipment_inventory_cost)->toBe(1234.5678)
        ->and($value->cost_method)->toBe('MOVING_AVERAGE');
    $ledger=DB::table('stock_ledger')->where('movement_type','SHIPMENT')->where('source_document_id',$shipment->id)->first();
    expect((float)$ledger->unit_cost)->toBe(12.345678)->and((float)$ledger->total_cost)->toBe(1234.5678);
});

it('distinguishes legitimate zero moving average from missing cost',function(){
    [$user,,,$so,$mo,$colorway,$size,$fg,$pl]=approvedFgFixture();
    $balance=StockBalance::withoutGlobalScopes()->where('company_id',1)->where('item_type','FG')->where('warehouse_id',$fg->id)
        ->where('style_id',$mo->style_id)->where('colorway_id',$colorway->id)->where('size_id',$size->id)->firstOrFail();
    $shipment=app(ShipmentService::class)->create($pl,['ship_date'=>now()->toDateString()],$user);
    expect(fn()=>app(ShipmentInventoryValuationService::class)->ship($shipment,$fg->id,$user))->toThrow(RuntimeException::class,'moving average is missing');
    $balance->update(['avg_cost'=>0]);
    app(ShipmentInventoryValuationService::class)->ship($shipment->fresh(),$fg->id,$user);
    expect((float)DB::table('shipment_inventory_valuations')->where('shipment_id',$shipment->id)->value('shipment_inventory_cost'))->toBe(0.0);
});

it('is retry safe and creates one ITS OUT and one valuation per line',function(){
    [$user,,,$so,$mo,$colorway,$size,$fg,$pl]=approvedFgFixture();
    StockBalance::withoutGlobalScopes()->where('company_id',1)->where('item_type','FG')->where('warehouse_id',$fg->id)
        ->where('style_id',$mo->style_id)->where('colorway_id',$colorway->id)->where('size_id',$size->id)->update(['avg_cost'=>9]);
    $shipment=app(ShipmentService::class)->create($pl,['ship_date'=>now()->toDateString()],$user);
    $service=app(ShipmentInventoryValuationService::class);$service->ship($shipment,$fg->id,$user);$service->ship($shipment->fresh(),$fg->id,$user);
    expect(DB::table('stock_movements')->where('movement_type','SHIPMENT')->where('source_document_id',$shipment->id)->count())->toBe(1)
        ->and(DB::table('shipment_inventory_valuations')->where('shipment_id',$shipment->id)->count())->toBe($shipment->lines()->count());
});

it('keeps migration additive and D-10 D-11 outside the implementation',function(){
    $migration=file_get_contents(database_path('migrations/2026_09_03_000032_add_d08_shipment_inventory_valuation.php'));
    $service=file_get_contents(app_path('Modules/Shipping/Services/ShipmentInventoryValuationService.php'));
    expect($migration)->toContain("Schema::create('shipment_inventory_valuations'")->not->toContain('DB::table(')->not->toContain('->update(');
    expect($service)->not->toContain('GlPostingService')->not->toContain('JournalService')->not->toContain('SHIPMENT_COGS');
});

it('exposes no manual cost or quantity mutation endpoint',function(){
    $routes=file_get_contents(app_path('Modules/Qc/routes/qc.php'));
    expect($routes)->toContain("shipments/{shipment}/valuation")->toContain("lines/{line}/valuation")
        ->not->toContain('manual-valuation')->not->toContain('moving-average/edit')->not->toContain('cogs/post');
});
