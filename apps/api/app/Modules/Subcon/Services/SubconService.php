<?php

namespace Modules\Subcon\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Cutting\Models\Bundle;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\MasterData\Models\Material;
use Modules\MasterData\Models\Supplier;
use Modules\MasterData\Models\Uom;
use Modules\Production\Models\ProductionOrder;
use Modules\Subcon\Models\SubconOrder;
use RuntimeException;

class SubconService
{
    public function __construct(private NumberingService $numbering,private InventoryTransactionService $its,private AuditService $audit){}

    public function createAndSend(int $companyId,ProductionOrder $mo,int $supplierId,array $lines,array $header,User $user):SubconOrder
    {
        if($lines===[])throw new RuntimeException('Subcon order wajib punya minimal 1 line.');
        return DB::transaction(function()use($companyId,$mo,$supplierId,$lines,$header,$user){
            $this->assertAccess($user,$companyId);
            $lockedMo=ProductionOrder::withoutGlobalScopes()->where('company_id',$companyId)->whereKey($mo->id)->lockForUpdate()->firstOrFail();
            if(in_array($lockedMo->status,['CLOSED','CANCELLED'],true))throw new RuntimeException('MO tidak dapat dikirim ke subcon.');
            $supplier=Supplier::withoutGlobalScopes()->where('company_id',$companyId)->whereKey($supplierId)->first();
            if($supplier===null||!$supplier->isSubcon())throw new RuntimeException('Supplier tidak ditemukan pada company atau bukan type SUBCON.');
            $warehouseId=(int)($header['warehouse_id']??0);
            if(!DB::table('warehouses')->where('company_id',$companyId)->where('id',$warehouseId)->exists())throw new RuntimeException('Warehouse subcon tidak ditemukan pada company ini.');
            $operationId=!empty($header['operation_id'])?(int)$header['operation_id']:null;
            if($operationId!==null&&!$lockedMo->routingVersion->operations()->where('operation_id',$operationId)->exists())throw new RuntimeException('Operation subcon tidak ada di routing snapshot MO.');
            $fee=(float)($header['fee_per_pcs']??0);if($fee<0)throw new RuntimeException('Fee per pcs tidak boleh negatif.');

            $seen=[];$normalized=[];
            foreach($lines as $line){
                $qty=(float)($line['qty_sent']??0);$materialId=!empty($line['material_id'])?(int)$line['material_id']:null;$bundleId=!empty($line['bundle_id'])?(int)$line['bundle_id']:null;
                if($qty<=0||($materialId===null&&$bundleId===null)||($materialId!==null&&$bundleId!==null))throw new RuntimeException('Line subcon harus berisi tepat satu material atau bundle dengan qty > 0.');
                $key=($materialId?'M'.$materialId:'B'.$bundleId);if(isset($seen[$key]))throw new RuntimeException('Line subcon duplikat.');$seen[$key]=true;
                $uomId=!empty($line['uom_id'])?(int)$line['uom_id']:null;
                if($materialId!==null){
                    if(!Material::withoutGlobalScopes()->where('company_id',$companyId)->whereKey($materialId)->exists())throw new RuntimeException('Material subcon tidak ditemukan pada company ini.');
                    if($uomId===null||!Uom::withoutGlobalScopes()->where('company_id',$companyId)->whereKey($uomId)->exists())throw new RuntimeException('UOM material subcon tidak valid.');
                }else{
                    if(!Bundle::withoutGlobalScopes()->where('company_id',$companyId)->where('production_order_id',$lockedMo->id)->whereKey($bundleId)->exists())throw new RuntimeException('Bundle subcon bukan milik MO/company ini.');
                }
                $normalized[]=compact('materialId','bundleId','qty','uomId');
            }

            $order=SubconOrder::create(['company_id'=>$companyId,'doc_no'=>$this->numbering->next($companyId,'JW'),'supplier_id'=>$supplier->id,'production_order_id'=>$lockedMo->id,'operation_id'=>$operationId,'sent_date'=>now()->toDateString(),'expected_return'=>$header['expected_return']??null,'fee_per_pcs'=>$fee,'status'=>'DRAFT','created_by'=>$user->id]);
            $itsLines=[];
            foreach($normalized as $row){$line=$order->lines()->create(['material_id'=>$row['materialId'],'bundle_id'=>$row['bundleId'],'qty_sent'=>$row['qty'],'qty_returned'=>0,'uom_id'=>$row['uomId']]);if($row['materialId'])$itsLines[]=['material_id'=>$row['materialId'],'warehouse_id'=>$warehouseId,'qty'=>$row['qty'],'uom_id'=>$row['uomId'],'source_document_line_id'=>$line->id];}
            if($itsLines)$this->its->post('SUBCON_OUT',['company_id'=>$companyId,'source_document_type'=>'subcon_orders','source_document_id'=>$order->id],$itsLines,$user);
            $order->update(['status'=>'SENT','updated_by'=>$user->id]);$this->audit->record('create',$order,after:['doc_no'=>$order->doc_no,'supplier'=>$supplier->code]);return $order->load('lines');
        });
    }

    public function receive(SubconOrder $order,array $returns,User $user):SubconOrder
    {
        if($returns===[])throw new RuntimeException('Return subcon wajib punya minimal 1 line.');
        return DB::transaction(function()use($order,$returns,$user){
            $locked=SubconOrder::withoutGlobalScopes()->whereKey($order->id)->lockForUpdate()->firstOrFail();$this->assertAccess($user,(int)$locked->company_id);
            if(!in_array($locked->status,['SENT','PARTIAL_RETURNED'],true))throw new RuntimeException("Subcon order {$locked->status} tidak bisa menerima return.");
            $seen=[];
            foreach($returns as $ret){
                $lineId=(int)($ret['line_id']??0);if(isset($seen[$lineId]))throw new RuntimeException('Return line subcon duplikat.');$seen[$lineId]=true;
                $line=$locked->lines()->whereKey($lineId)->lockForUpdate()->first();if($line===null)throw new RuntimeException('Return line bukan milik subcon order ini.');
                $qty=(float)($ret['qty_returned']??0);$remaining=(float)$line->qty_sent-(float)$line->qty_returned;
                if($qty<=0||$qty-$remaining>0.0001)throw new RuntimeException("Return {$qty} melebihi sisa kirim {$remaining}.");
                $warehouseId=(int)($ret['warehouse_id']??0);if(!DB::table('warehouses')->where('company_id',$locked->company_id)->where('id',$warehouseId)->exists())throw new RuntimeException('Warehouse return tidak ditemukan pada company ini.');
                $fee=$locked->fees()->create(['return_date'=>now()->toDateString(),'qty_returned'=>$qty,'fee_per_pcs'=>(float)$locked->fee_per_pcs,'total_fee'=>round($qty*(float)$locked->fee_per_pcs,4)]);
                if($line->material_id)$this->its->post('SUBCON_IN',['company_id'=>$locked->company_id,'source_document_type'=>'subcon_fees','source_document_id'=>$fee->id],[['material_id'=>$line->material_id,'warehouse_id'=>$warehouseId,'qty'=>$qty,'uom_id'=>$line->uom_id,'source_document_line_id'=>$line->id]],$user);
                $line->increment('qty_returned',$qty);
            }
            $full=$locked->lines()->whereColumn('qty_returned','<','qty_sent')->doesntExist();$locked->update(['status'=>$full?'RETURNED':'PARTIAL_RETURNED','updated_by'=>$user->id]);$this->audit->record('update',$locked,after:['status'=>$locked->status,'returns'=>count($returns)]);return $locked->fresh(['lines','fees']);
        });
    }

    private function assertAccess(User $user,int $companyId):void{if((int)$user->company_id!==$companyId&&!$user->companies()->whereKey($companyId)->exists())throw new RuntimeException('User tidak memiliki akses ke company subcon.');}
}
