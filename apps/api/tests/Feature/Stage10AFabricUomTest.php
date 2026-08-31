<?php

use Illuminate\Support\Facades\DB;
use Modules\Cutting\Services\CuttingService;
use Modules\Inventory\Models\StockBalance;
use Modules\MasterData\Models\Material;
use Modules\MasterData\Models\Uom;
use Modules\MasterData\Services\UomConversionService;
use Modules\Production\Services\MaterialIssueService;
use Modules\Receiving\Models\FabricRoll;

it('mengonversi yard dan meter dengan faktor panjang standar',function(){
 $m=Uom::create(['company_id'=>1,'code'=>'MTR','name'=>'Meter']);$y=Uom::create(['company_id'=>1,'code'=>'YRD','name'=>'Yard']);$material=Material::create(['company_id'=>1,'code'=>'LEN-'.uniqid(),'name'=>'Length fabric','type'=>'FABRIC','tracking_level'=>'ROLL','buy_uom_id'=>$y->id,'use_uom_id'=>$m->id]);$svc=app(UomConversionService::class);
 expect($svc->convert(1,$material->id,1,$y->id,$m->id))->toBe(0.9144)->and($svc->convert(1,$material->id,0.9144,$m->id,$y->id))->toBe(1.0);
});

it('BR-042 mengembalikan hanya dispatched leftover tanpa double count stok',function(){
 [$user,$fabric,, $uom,, $warehouse,$mo,,,$colorway,$size]=shopFixture();$roll=FabricRoll::where('roll_no','R001')->firstOrFail();
 app(MaterialIssueService::class)->issue($mo,$warehouse->id,[['material_id'=>$fabric->id,'qty'=>200,'uom_id'=>$uom->id,'roll_id'=>$roll->id]],$user);
 $cutting=app(CuttingService::class);$cut=$cutting->create($mo->fresh(),[['colorway_id'=>$colorway->id,'size_id'=>$size->id,'qty_cut'=>100]],$user);
 $cutting->recordMarker($cut,[['roll_id'=>$roll->id,'marker_length_m'=>9.5,'plies'=>20,'qty_fabric_used_m'=>190]],$user);
 $return=app(MaterialIssueService::class)->returnLeftover($mo->fresh(),$roll->fresh(),$warehouse->id,$user);
 $balance=StockBalance::withoutGlobalScopes()->where('material_id',$fabric->id)->where('warehouse_id',$warehouse->id)->where('roll_id',$roll->id)->firstOrFail();$dispatch=DB::table('fabric_dispatch_balances')->where('production_order_id',$mo->id)->where('roll_id',$roll->id)->first();
 expect((float)$return->qty_returned_use)->toBe(10.0)->and((float)$dispatch->qty_dispatched)->toBe(200.0)->and((float)$dispatch->qty_consumed)->toBe(190.0)->and((float)$dispatch->qty_returned)->toBe(10.0)->and((float)$balance->on_hand)->toBe(110.0)->and($roll->fresh()->status)->toBe('RELEASED');
 expect(fn()=>app(MaterialIssueService::class)->returnLeftover($mo->fresh(),$roll->fresh(),$warehouse->id,$user))->toThrow(RuntimeException::class,'sudah dikembalikan');
});
