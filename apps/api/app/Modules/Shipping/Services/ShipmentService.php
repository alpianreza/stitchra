<?php

namespace Modules\Shipping\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\MasterData\Models\Uom;
use Modules\Packing\Models\PackingList;
use Modules\Sales\Models\SalesOrder;
use Modules\Shipping\Models\Shipment;
use RuntimeException;

class ShipmentService
{
    public function __construct(private NumberingService $numbering,private InventoryTransactionService $its,private AuditService $audit){}
    public function create(PackingList $pl,array $header,User $user):Shipment
    {
        return DB::transaction(function()use($pl,$header,$user){
            $locked=PackingList::withoutGlobalScopes()->whereKey($pl->id)->lockForUpdate()->firstOrFail();$this->assertAccess($user,(int)$locked->company_id);
            if($locked->status!=='APPROVED')throw new RuntimeException('Shipment hanya dari packing list APPROVED.');
            if(Shipment::withoutGlobalScopes()->where('packing_list_id',$locked->id)->exists())throw new RuntimeException('Packing list sudah memiliki shipment.');
            if(empty($header['ship_date']))throw new RuntimeException('Ship date wajib diisi.');
            $shipment=Shipment::create(['company_id'=>$locked->company_id,'doc_no'=>$this->numbering->next($locked->company_id,'SHP'),'sales_order_id'=>$locked->sales_order_id,'packing_list_id'=>$locked->id,'ship_date'=>$header['ship_date'],'forwarder'=>$header['forwarder']??null,'booking_no'=>$header['booking_no']??null,'container_no'=>$header['container_no']??null,'vessel_flight'=>$header['vessel_flight']??null,'port_of_loading'=>$header['port_of_loading']??null,'port_of_discharge'=>$header['port_of_discharge']??null,'status'=>'DRAFT','tolerance_check'=>'PENDING','created_by'=>$user->id]);
            $rows=DB::table('carton_lines')->join('cartons','cartons.id','=','carton_lines.carton_id')->where('cartons.packing_list_id',$locked->id)->selectRaw('style_id,colorway_id,size_id,SUM(qty) qty')->groupBy('style_id','colorway_id','size_id')->get();
            if($rows->isEmpty())throw new RuntimeException('Packing list tidak memiliki carton line.');
            foreach($rows as $row)$shipment->lines()->create(['style_id'=>$row->style_id,'colorway_id'=>$row->colorway_id,'size_id'=>$row->size_id,'qty_shipped'=>(float)$row->qty]);
            $this->checkToleranceLocked($shipment->fresh('lines'));$this->audit->record('create',$shipment,after:['packing_list'=>$locked->doc_no]);return $shipment->fresh('lines');
        });
    }
    public function checkTolerance(Shipment $shipment):void{DB::transaction(function()use($shipment){$locked=Shipment::withoutGlobalScopes()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();$this->checkToleranceLocked($locked->load('lines'));});}
    private function checkToleranceLocked(Shipment $shipment):void
    {
        $so=SalesOrder::withoutGlobalScopes()->with('lines','customer')->where('company_id',$shipment->company_id)->whereKey($shipment->sales_order_id)->lockForUpdate()->firstOrFail();$tol=(float)($so->tolerance_pct??$so->customer?->shipment_tolerance_pct??0);$result='OK';
        foreach($so->lines as $line){$current=(float)$shipment->lines->first(fn($x)=>(int)$x->style_id===(int)$line->style_id&&(int)$x->colorway_id===(int)$line->colorway_id&&(int)$x->size_id===(int)$line->size_id)?->qty_shipped;$prior=(float)DB::table('shipment_lines')->join('shipments','shipments.id','=','shipment_lines.shipment_id')->where('shipments.sales_order_id',$so->id)->where('shipments.status','SHIPPED')->where('shipment_lines.style_id',$line->style_id)->where('shipment_lines.colorway_id',$line->colorway_id)->where('shipment_lines.size_id',$line->size_id)->sum('shipment_lines.qty_shipped');$ordered=(float)$line->qty;$projected=$prior+$current;if($projected>$ordered*(1+$tol/100)+0.0001)$result='OVER';elseif($projected<$ordered*(1-$tol/100)-0.0001&&$result!=='OVER')$result='UNDER';}
        $shipment->update(['tolerance_check'=>$result]);
    }
    public function approveOverTolerance(Shipment $shipment,User $user):Shipment{return DB::transaction(function()use($shipment,$user){$locked=Shipment::withoutGlobalScopes()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();$this->assertAccess($user,(int)$locked->company_id);if(!in_array($locked->status,['DRAFT','READY'],true))throw new RuntimeException('Hanya shipment belum dikirim yang dapat di-approve.');if($locked->tolerance_check==='OK')throw new RuntimeException('Shipment dalam toleransi tidak butuh override.');$locked->update(['over_tolerance_approved'=>true,'updated_by'=>$user->id]);$this->audit->record('update',$locked,after:['over_tolerance_approved'=>true,'tolerance_check'=>$locked->tolerance_check]);return $locked->fresh();});}
    public function ship(Shipment $shipment,int $warehouseId,User $user):Shipment
    {
        return DB::transaction(function()use($shipment,$warehouseId,$user){$locked=Shipment::withoutGlobalScopes()->with('lines')->whereKey($shipment->id)->lockForUpdate()->firstOrFail();$this->assertAccess($user,(int)$locked->company_id);if(!in_array($locked->status,['DRAFT','READY'],true))throw new RuntimeException("Shipment {$locked->status} tidak bisa dikirim.");if($locked->tolerance_check!=='OK'&&!$locked->over_tolerance_approved)throw new RuntimeException('BR-021: shipment di luar toleransi buyer — approval wajib.');if(!DB::table('warehouses')->where('company_id',$locked->company_id)->where('type','FG')->where('id',$warehouseId)->exists())throw new RuntimeException('Warehouse shipment wajib FG pada company yang sama.');$pcs=Uom::withoutGlobalScopes()->where('company_id',$locked->company_id)->where('code','PCS')->first();if($pcs===null)throw new RuntimeException('PCS UOM belum dikonfigurasi.');$pl=PackingList::withoutGlobalScopes()->where('company_id',$locked->company_id)->whereKey($locked->packing_list_id)->lockForUpdate()->firstOrFail();if($pl->status!=='APPROVED')throw new RuntimeException('Packing list shipment tidak lagi APPROVED.');$lines=$locked->lines->map(fn($l)=>['item_type'=>'FG','style_id'=>$l->style_id,'colorway_id'=>$l->colorway_id,'size_id'=>$l->size_id,'warehouse_id'=>$warehouseId,'qty'=>(float)$l->qty_shipped,'uom_id'=>$pcs->id,'source_document_line_id'=>$l->id])->all();$this->its->post('SHIPMENT',['company_id'=>$locked->company_id,'source_document_type'=>'shipments','source_document_id'=>$locked->id],$lines,$user);$locked->update(['status'=>'SHIPPED','updated_by'=>$user->id]);$pl->update(['status'=>'SHIPPED','updated_by'=>$user->id]);$so=SalesOrder::withoutGlobalScopes()->with('lines','customer')->where('company_id',$locked->company_id)->whereKey($locked->sales_order_id)->lockForUpdate()->firstOrFail();$tol=(float)($so->tolerance_pct??$so->customer?->shipment_tolerance_pct??0);$complete=$so->lines->every(function($line)use($so,$tol){$qty=(float)DB::table('shipment_lines')->join('shipments','shipments.id','=','shipment_lines.shipment_id')->where('shipments.sales_order_id',$so->id)->where('shipments.status','SHIPPED')->where('shipment_lines.style_id',$line->style_id)->where('shipment_lines.colorway_id',$line->colorway_id)->where('shipment_lines.size_id',$line->size_id)->sum('shipment_lines.qty_shipped');return $qty+0.0001>=(float)$line->qty*(1-$tol/100);});$so->update(['status'=>$complete?'CLOSED':'IN_PROGRESS','updated_by'=>$user->id]);$this->audit->record('update',$locked,after:['status'=>'SHIPPED','so'=>$so->doc_no]);return $locked->fresh();});
    }
    private function assertAccess(User $user,int $companyId):void{if((int)$user->company_id!==$companyId&&!$user->companies()->whereKey($companyId)->exists())throw new RuntimeException('User tidak memiliki akses ke company shipment.');}
}
