<?php

/**
 * Shared ERP fixtures. Loaded once from tests/Pest.php.
 */

use Modules\Core\Models\User;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\MasterData\Models\Color;
use Modules\MasterData\Models\Colorway;
use Modules\MasterData\Models\Customer;
use Modules\MasterData\Models\CustomerAqlConfig;
use Modules\MasterData\Models\Material;
use Modules\MasterData\Models\Operation;
use Modules\MasterData\Models\Size;
use Modules\MasterData\Models\Style;
use Modules\MasterData\Models\Supplier;
use Modules\MasterData\Models\Uom;
use Modules\MasterData\Models\Warehouse;
use Modules\ProductDev\Services\BomService;
use Modules\ProductDev\Services\RoutingService;
use Modules\Production\Services\ProductionOrderService;
use Modules\Purchasing\Services\PurchasingService;
use Modules\Receiving\Models\FabricRoll;
use Modules\Receiving\Services\ReceivingService;
use Modules\Sales\Services\SalesOrderService;

function erpApprovedStyle(User $user, float $qtyPerPcs = 2.0, float $smv = 15): array
{
    $uom=Uom::create(['company_id'=>1,'code'=>'MTR'.substr(uniqid(),-3),'name'=>'Meter']);
    $fabric=Material::create(['company_id'=>1,'code'=>'FAB-'.uniqid(),'name'=>'Kain','type'=>'FABRIC','tracking_level'=>'LOT']);
    $style=Style::create(['company_id'=>1,'style_no'=>'ST-'.uniqid(),'category'=>'WOVEN']);
    $bom=app(BomService::class);$bom->markApproved($bom->createVersion($style->id,[['material_id'=>$fabric->id,'qty_per_pcs'=>$qtyPerPcs,'uom_id'=>$uom->id]],$user));
    $operation=Operation::create(['company_id'=>1,'code'=>'OP-'.uniqid(),'name'=>'Jahit']);
    $routing=app(RoutingService::class);$routing->markApproved($routing->createVersion($style->id,[['operation_id'=>$operation->id,'smv'=>$smv]],$user));
    return [$style,$fabric,$uom];
}

function erpConfirmedSo(User $user, Style $style, float $qty = 100, float $price = 15, ?Customer $customer = null): array
{
    $customer=$customer??Customer::create(['company_id'=>1,'code'=>'C-'.uniqid(),'name'=>'Buyer']);
    $color=Color::create(['company_id'=>1,'code'=>'CLR'.substr(uniqid(),-2),'name'=>'Black']);
    $colorway=Colorway::create(['company_id'=>1,'style_id'=>$style->id,'color_id'=>$color->id]);
    $size=Size::create(['company_id'=>1,'code'=>'SZ'.substr(uniqid(),-2)]);
    $service=app(SalesOrderService::class);
    $so=$service->create(1,['customer_id'=>$customer->id,'order_date'=>now()->toDateString()],[['style_id'=>$style->id,'colorway_id'=>$colorway->id,'size_id'=>$size->id,'qty'=>$qty,'price'=>$price]],$user);
    $so->update(['status'=>'APPROVED']);
    return [$service->confirm($so),$colorway,$size,$customer];
}

function qcFixture(float $soQty = 1200): array
{
    $user=User::factory()->create(['company_id'=>1]);
    $customer=Customer::create(['company_id'=>1,'code'=>'C-'.uniqid(),'name'=>'Buyer A','shipment_tolerance_pct'=>5]);
    CustomerAqlConfig::create(['company_id'=>1,'customer_id'=>$customer->id,'inspection_level'=>'G2','aql_major'=>2.5,'aql_minor'=>4.0,'aql_critical'=>0]);
    [$style,$fabric,$uom]=erpApprovedStyle($user);$style->update(['customer_id'=>$customer->id]);
    [$so,$colorway,$size]=erpConfirmedSo($user,$style,$soQty,15,$customer);
    $mo=app(ProductionOrderService::class)->createFromSalesOrder($so,$user)[0];
    $mo->update(['status'=>'FINISHING','qty_produced'=>$soQty]);
    return [$user,$customer,$style,$so,$mo->fresh(),$colorway,$size];
}

function packFixture(float $soQty = 100, float $tolerance = 5): array
{
    [$user,$customer,$style,$so,$mo,$colorway,$size]=qcFixture($soQty);$so->update(['tolerance_pct'=>$tolerance]);
    $fg=Warehouse::create(['company_id'=>1,'code'=>'FG-'.substr(uniqid(),-3),'name'=>'Gudang FG','type'=>'FG']);
    Uom::firstOrCreate(['company_id'=>1,'code'=>'PCS'],['name'=>'Pcs']);
    return [$user,$customer,$style,$so->fresh(),$mo->fresh(),$colorway,$size,$fg];
}

function shopFixture(): array
{
    $user=User::factory()->create(['company_id'=>1]);
    $uomMtr=Uom::create(['company_id'=>1,'code'=>'MTR'.substr(uniqid(),-3),'name'=>'Meter']);
    $uomPcs=Uom::create(['company_id'=>1,'code'=>'PCS'.substr(uniqid(),-3),'name'=>'Pcs']);
    $fabric=Material::create(['company_id'=>1,'code'=>'FAB-'.uniqid(),'name'=>'Twill','type'=>'FABRIC','tracking_level'=>'ROLL','gsm'=>200,'width_cm'=>150,'buy_uom_id'=>$uomMtr->id,'use_uom_id'=>$uomMtr->id]);
    $trim=Material::create(['company_id'=>1,'code'=>'TRM-'.uniqid(),'name'=>'Label','type'=>'TRIM','tracking_level'=>'LOT','use_uom_id'=>$uomPcs->id]);
    $style=Style::create(['company_id'=>1,'style_no'=>'ST-'.uniqid(),'category'=>'WOVEN']);
    $bom=app(BomService::class);$bom->markApproved($bom->createVersion($style->id,[
        ['material_id'=>$fabric->id,'qty_per_pcs'=>2.0,'uom_id'=>$uomMtr->id,'is_backflush'=>false],
        ['material_id'=>$trim->id,'qty_per_pcs'=>5,'uom_id'=>$uomPcs->id,'is_backflush'=>true],
    ],$user));
    $op1=Operation::create(['company_id'=>1,'code'=>'OP1-'.uniqid(),'name'=>'Jahit sisi']);
    $op2=Operation::create(['company_id'=>1,'code'=>'OP2-'.uniqid(),'name'=>'Pasang lengan']);
    $routing=app(RoutingService::class);$routing->markApproved($routing->createVersion($style->id,[
        ['operation_id'=>$op1->id,'smv'=>6,'seq'=>1],['operation_id'=>$op2->id,'smv'=>8,'seq'=>2],
    ],$user));
    [$so,$colorway,$size]=erpConfirmedSo($user,$style,100,15);
    $warehouse=Warehouse::create(['company_id'=>1,'code'=>'RM-'.substr(uniqid(),-3),'name'=>'RM','type'=>'RM']);
    $its=app(InventoryTransactionService::class);
    $its->post('OPENING',['company_id'=>1,'source_document_type'=>'tests','source_document_id'=>1],[['material_id'=>$trim->id,'warehouse_id'=>$warehouse->id,'qty'=>1000,'uom_id'=>$uomPcs->id,'unit_cost'=>0.1]],$user);
    $supplier=Supplier::create(['company_id'=>1,'code'=>'SUP-'.uniqid(),'name'=>'Tex','type'=>'FABRIC']);
    $po=app(PurchasingService::class)->createPo(1,['supplier_id'=>$supplier->id,'order_date'=>now()->toDateString()],[['material_id'=>$fabric->id,'qty'=>500,'uom_id'=>$uomMtr->id,'unit_price'=>10]],$user);$po->update(['status'=>'APPROVED']);
    $gr=app(ReceivingService::class)->createAndPost(1,['purchase_order_id'=>$po->id,'warehouse_id'=>$warehouse->id,'received_date'=>now()->toDateString()],[[
        'po_line_id'=>$po->lines->first()->id,'material_id'=>$fabric->id,'qty_received'=>500,'uom_id'=>$uomMtr->id,'unit_price'=>10,
        'rolls'=>[['roll_no'=>'R001','qty_buy'=>300,'qty_meter_actual'=>300],['roll_no'=>'R002','qty_buy'=>200,'qty_meter_actual'=>200]],
    ]],$user);
    $grLine=$gr->lines->first();
    foreach(FabricRoll::where('gr_line_id',$grLine->id)->get() as $roll){
        $its->releaseQualityHold(1,['material_id'=>$fabric->id,'warehouse_id'=>$warehouse->id,'roll_id'=>$roll->id,'uom_id'=>$uomMtr->id,'source_document_id'=>$grLine->id],(float)$roll->qty_buy,$user);
        $roll->update(['status'=>'RELEASED']);
    }
    $moService=app(ProductionOrderService::class);$mo=$moService->release($moService->createFromSalesOrder($so,$user)[0],$warehouse->id,$user);
    return [$user,$fabric,$trim,$uomMtr,$uomPcs,$warehouse,$mo,$op1,$op2,$colorway,$size];
}
