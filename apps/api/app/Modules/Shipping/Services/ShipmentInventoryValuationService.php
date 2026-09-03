<?php

namespace Modules\Shipping\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Inventory\Models\StockBalance;
use Modules\Packing\Models\PackingList;
use Modules\Shipping\Models\Shipment;
use Modules\Shipping\Models\ShipmentInventoryValuation;
use RuntimeException;

class ShipmentInventoryValuationService
{
    public const EVENT='ITS_SHIPMENT_OUT';
    public const METHOD='MOVING_AVERAGE';

    public function __construct(private ShipmentService $shipments,private AuditService $audit){}

    public function ship(Shipment $shipment,int $warehouseId,User $user):Shipment
    {
        return DB::transaction(function()use($shipment,$warehouseId,$user):Shipment{
            $locked=Shipment::withoutGlobalScopes()->with('lines')->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $this->access($user,(int)$locked->company_id);
            $existing=ShipmentInventoryValuation::withoutGlobalScopes()->where('company_id',$locked->company_id)->where('shipment_id',$locked->id)->lockForUpdate()->get();
            if($locked->status==='SHIPPED'){
                $this->assertReplay($locked,$existing,$warehouseId);
                return $locked->fresh(['lines','packingList']);
            }
            if($existing->isNotEmpty())throw new RuntimeException('CONFLICT: unshipped Shipment already has D-08 valuation.');
            $pl=PackingList::withoutGlobalScopes()->where('company_id',$locked->company_id)->whereKey($locked->packing_list_id)->lockForUpdate()->firstOrFail();
            if(!$pl->production_order_id)throw new RuntimeException('FAIL_CLOSED: Shipment Packing List has no MO lineage.');
            $receipt=DB::table('stock_movements')->where('company_id',$locked->company_id)->where('movement_type','PRODUCTION_RECEIPT')
                ->where('source_document_type','packing_lists')->where('source_document_id',$pl->id)->lockForUpdate()->first();
            if(!$receipt)throw new RuntimeException('FAIL_CLOSED: Packing List has no authoritative PRODUCTION_RECEIPT.');
            $snapshots=[];
            foreach($locked->lines as $line){
                $key=['company_id'=>(int)$locked->company_id,'item_type'=>'FG','material_id'=>null,'style_id'=>(int)$line->style_id,
                    'colorway_id'=>$line->colorway_id===null?null:(int)$line->colorway_id,'size_id'=>$line->size_id===null?null:(int)$line->size_id,
                    'warehouse_id'=>$warehouseId,'location_id'=>null,'lot_no'=>null,'roll_id'=>null,'ownership'=>'COMPANY'];
                $normalized=$key;ksort($normalized);$balanceKey=hash('sha256',json_encode($normalized,JSON_THROW_ON_ERROR));
                DB::table('stock_balance_locks')->insertOrIgnore(['balance_key'=>$balanceKey,'created_at'=>now(),'updated_at'=>now()]);
                DB::table('stock_balance_locks')->where('balance_key',$balanceKey)->lockForUpdate()->first();
                $balance=StockBalance::withoutGlobalScopes()->where($key)->lockForUpdate()->first();
                if(!$balance)throw new RuntimeException('FAIL_CLOSED: company-owned FG inventory balance is missing.');
                $qty=(float)$line->qty_shipped;$available=$balance->available();
                if($qty<=0||$available+0.0001<$qty)throw new RuntimeException('FAIL_CLOSED: insufficient company-owned FG stock for Shipment valuation.');
                if($balance->avg_cost===null)throw new RuntimeException('FAIL_CLOSED: prevailing FG moving average is missing.');
                $unit=round((float)$balance->avg_cost,6);
                if($unit<0)throw new RuntimeException('CONFLICT: prevailing FG moving average is negative.');
                $snapshots[$line->id]=['balance_id'=>$balance->id,'qty'=>$qty,'unit_cost'=>$unit,'total_cost'=>round($qty*$unit,4),
                    'on_hand_before'=>(float)$balance->on_hand,'balance_updated_at'=>$balance->updated_at?->toISOString(),'key'=>$key];
            }
            $shipped=$this->shipments->ship($locked,$warehouseId,$user);
            $movement=DB::table('stock_movements')->where('company_id',$locked->company_id)->where('movement_type','SHIPMENT')
                ->where('source_document_type','shipments')->where('source_document_id',$locked->id)->lockForUpdate()->first();
            if(!$movement)throw new RuntimeException('FAIL_CLOSED: ITS SHIPMENT movement was not created.');
            $currency=(string)DB::table('companies')->where('id',$locked->company_id)->value('base_currency');
            foreach($locked->lines as $line){
                $snapshot=$snapshots[$line->id];
                $ledger=DB::table('stock_ledger')->where('company_id',$locked->company_id)->where('movement_type','SHIPMENT')
                    ->where('source_document_type','shipments')->where('source_document_id',$locked->id)
                    ->where('source_document_line_id',$line->id)->lockForUpdate()->first();
                if(!$ledger)throw new RuntimeException('FAIL_CLOSED: ITS SHIPMENT ledger line is missing.');
                if($ledger->ownership!=='COMPANY'||$ledger->item_type!=='FG')throw new RuntimeException('CONFLICT: ITS SHIPMENT ledger is not company-owned FG.');
                if($ledger->unit_cost===null&&$ledger->total_cost===null){
                    DB::table('stock_ledger')->where('id',$ledger->id)->where('company_id',$locked->company_id)->update(['unit_cost'=>$snapshot['unit_cost'],'total_cost'=>$snapshot['total_cost']]);
                }elseif(abs((float)$ledger->unit_cost-$snapshot['unit_cost'])>0.000001||abs((float)$ledger->total_cost-$snapshot['total_cost'])>0.0001){
                    throw new RuntimeException('CONFLICT: ITS SHIPMENT ledger cost differs from pre-OUT moving average.');
                }
                $payload=['company_id'=>(int)$locked->company_id,'shipment_id'=>$locked->id,'shipment_line_id'=>$line->id,'event'=>self::EVENT,
                    'style_id'=>(int)$line->style_id,'colorway_id'=>$line->colorway_id===null?null:(int)$line->colorway_id,'size_id'=>$line->size_id===null?null:(int)$line->size_id,
                    'warehouse_id'=>$warehouseId,'quantity'=>$snapshot['qty'],'moving_average'=>$snapshot['unit_cost'],'on_hand_before'=>$snapshot['on_hand_before'],
                    'balance_id'=>$snapshot['balance_id'],'balance_updated_at'=>$snapshot['balance_updated_at'],'packing_list_id'=>$pl->id,
                    'production_receipt_movement_id'=>$receipt->id,'shipment_movement_id'=>$movement->id,'shipment_ledger_id'=>$ledger->id,'valuation_version'=>1];
                $hash=hash('sha256',json_encode($payload,JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));
                $valuation=ShipmentInventoryValuation::create(['company_id'=>$locked->company_id,'shipment_id'=>$locked->id,'shipment_line_id'=>$line->id,
                    'packing_list_id'=>$pl->id,'production_order_id'=>$pl->production_order_id,'production_receipt_movement_id'=>$receipt->id,
                    'shipment_movement_id'=>$movement->id,'shipment_ledger_id'=>$ledger->id,'stock_balance_id'=>$snapshot['balance_id'],'item_type'=>'FG',
                    'style_id'=>$line->style_id,'colorway_id'=>$line->colorway_id,'size_id'=>$line->size_id,'warehouse_id'=>$warehouseId,'ownership'=>'COMPANY',
                    'shipment_quantity'=>$snapshot['qty'],'moving_average_unit_cost'=>$snapshot['unit_cost'],'shipment_inventory_cost'=>$snapshot['total_cost'],
                    'currency'=>$currency,'cost_method'=>self::METHOD,'valuation_event'=>self::EVENT,'valuation_version'=>1,'on_hand_before'=>$snapshot['on_hand_before'],
                    'source_hash'=>$hash,'created_by'=>$user->id,'valued_at'=>now(),'created_at'=>now()]);
                $this->audit->record('create',$valuation,after:['policy'=>'D-08','source_hash'=>$hash,'shipment_movement_id'=>$movement->id]);
            }
            return $shipped->fresh(['lines','packingList']);
        });
    }

    public function valuation(Shipment $shipment,User $user,?int $lineId=null):array
    {
        $loaded=Shipment::withoutGlobalScopes()->with('lines','packingList.productionOrder')->whereKey($shipment->id)->firstOrFail();
        $this->access($user,(int)$loaded->company_id);
        $query=ShipmentInventoryValuation::withoutGlobalScopes()->where('company_id',$loaded->company_id)->where('shipment_id',$loaded->id);
        if($lineId!==null)$query->where('shipment_line_id',$lineId);
        $rows=$query->orderBy('shipment_line_id')->get();
        if($lineId!==null&&!$loaded->lines->contains('id',$lineId))throw new RuntimeException('Shipment line does not belong to Shipment/company.');
        return['status'=>$rows->isEmpty()?'NOT_VALUED':'VALUED','shipment'=>['id'=>$loaded->id,'doc_no'=>$loaded->doc_no,'status'=>$loaded->status],
            'packing_list'=>['id'=>$loaded->packingList?->id,'doc_no'=>$loaded->packingList?->doc_no],
            'production_order'=>['id'=>$loaded->packingList?->productionOrder?->id,'doc_no'=>$loaded->packingList?->productionOrder?->doc_no],
            'valuation'=>['method'=>self::METHOD,'event'=>self::EVENT,'line_count'=>$rows->count(),'total_cost'=>round((float)$rows->sum('shipment_inventory_cost'),4),'rows'=>$rows],
            'cogs'=>['status'=>'NOT_IMPLEMENTED','handoff'=>'D-10 consumes valued company-owned ITS SHIPMENT total cost']];
    }

    public function lineage(Shipment $shipment,User $user):array
    {
        $base=$this->shipments->lineage($shipment,$user);$base['inventory_valuation']=$this->valuation($shipment,$user);
        return $base;
    }

    private function assertReplay(Shipment $shipment,$existing,int $warehouseId):void
    {
        if($existing->count()!==$shipment->lines->count())throw new RuntimeException('CONFLICT: SHIPPED shipment has incomplete D-08 valuation.');
        foreach($shipment->lines as $line){
            $row=$existing->firstWhere('shipment_line_id',$line->id);
            if(!$row||(int)$row->company_id!==(int)$shipment->company_id||(int)$row->warehouse_id!==$warehouseId
                ||$row->valuation_event!==self::EVENT||$row->cost_method!==self::METHOD
                ||(int)$row->style_id!==(int)$line->style_id||(int)$row->colorway_id!==(int)$line->colorway_id
                ||(int)$row->size_id!==(int)$line->size_id||abs((float)$row->shipment_quantity-(float)$line->qty_shipped)>0.0001){
                throw new RuntimeException('CONFLICT: D-08 retry payload differs from immutable Shipment valuation.');
            }
        }
    }

    private function access(User $user,int $companyId):void
    {
        if((int)$user->company_id!==$companyId&&!$user->companies()->whereKey($companyId)->exists())throw new RuntimeException('User does not have access to the Shipment company.');
    }
}
