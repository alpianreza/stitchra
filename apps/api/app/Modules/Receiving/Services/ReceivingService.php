<?php

namespace Modules\Receiving\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\MasterData\Models\Warehouse;
use Modules\MasterData\Services\UomConversionService;
use Modules\Purchasing\Models\PoLine;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Receiving\Models\GoodsReceipt;
use RuntimeException;

class ReceivingService
{
    public function __construct(private NumberingService $numbering, private InventoryTransactionService $its, private AuditService $audit, private UomConversionService $uoms) {}

    public function createAndPost(int $companyId, array $header, array $lines, User $user): GoodsReceipt
    {
        if ($lines === []) throw new RuntimeException('GR wajib punya minimal 1 line.');
        $ids=array_map(fn($l)=>(int)($l['po_line_id']??0),$lines);
        if(in_array(0,$ids,true)||count($ids)!==count(array_unique($ids))) throw new RuntimeException('Setiap PO line hanya boleh muncul satu kali dalam satu GR.');
        return DB::transaction(function()use($companyId,$header,$lines,$user){
            $po=PurchaseOrder::withoutGlobalScopes()->where('company_id',$companyId)->whereKey((int)($header['purchase_order_id']??0))->lockForUpdate()->first();
            if(!$po||!in_array($po->status,['APPROVED','PARTIAL_RECEIVED'],true)) throw new RuntimeException('PO tidak valid untuk penerimaan.');
            $warehouse=Warehouse::withoutGlobalScopes()->where('company_id',$companyId)->whereKey((int)($header['warehouse_id']??0))->first();
            if(!$warehouse) throw new RuntimeException('Warehouse tidak ditemukan pada company aktif.');
            $gr=GoodsReceipt::create(['company_id'=>$companyId,'doc_no'=>$this->numbering->next($companyId,'GR'),'purchase_order_id'=>$po->id,'warehouse_id'=>$warehouse->id,'received_date'=>$header['received_date'],'delivery_note_no'=>$header['delivery_note_no']??null,'status'=>'DRAFT','created_by'=>$user->id]);
            $itsLines=[];
            foreach($lines as $lineData){
                $qty=(float)($lineData['qty_received']??0);if($qty<=0)throw new RuntimeException('Qty penerimaan wajib lebih besar dari nol.');
                $poLine=PoLine::query()->where('purchase_order_id',$po->id)->whereKey((int)$lineData['po_line_id'])->lockForUpdate()->first();
                if(!$poLine)throw new RuntimeException('PO line tidak berasal dari PO yang dipilih.');
                $remaining=(float)$poLine->qty-(float)$poLine->received_qty;if($qty-$remaining>0.0001)throw new RuntimeException("Qty penerimaan melebihi sisa PO line ({$remaining}).");
                $material=$poLine->material()->withoutGlobalScopes()->where('company_id',$companyId)->first();if(!$material)throw new RuntimeException('Material PO line tidak berasal dari company aktif.');
                $rolls=$lineData['rolls']??[];
                if($material->isRollTracked()){
                    if($rolls===[]||empty($material->use_uom_id))throw new RuntimeException('Fabric roll wajib memiliki roll dan use UOM.');
                    if(abs(array_sum(array_map(fn($r)=>(float)($r['qty_buy']??0),$rolls))-$qty)>0.0001)throw new RuntimeException('Total qty_buy seluruh roll harus sama dengan qty_received.');
                }elseif($rolls!==[])throw new RuntimeException('Roll hanya boleh untuk material tracking ROLL.');
                $grLine=$gr->lines()->create(['po_line_id'=>$poLine->id,'material_id'=>$poLine->material_id,'qty_received'=>$qty,'uom_id'=>$poLine->uom_id,'unit_price'=>$poLine->unit_price,'status'=>'QUALITY_HOLD']);
                if($material->isRollTracked())foreach($rolls as $r){
                    $qtyBuy=(float)($r['qty_buy']??0);if($qtyBuy<=0)throw new RuntimeException('qty_buy roll wajib lebih besar dari nol.');
                    $gsm=(float)($r['gsm_actual']??$material->gsm??0);$width=(float)($r['width_actual_cm']??$material->width_cm??0);
                    if(isset($r['qty_use_actual']))$qtyUse=(float)$r['qty_use_actual'];
                    elseif(isset($r['qty_meter_actual']))$qtyUse=$this->uoms->fromMeters($companyId,(int)$material->use_uom_id,(float)$r['qty_meter_actual']);
                    else{
                        try{$qtyUse=$this->uoms->convert($companyId,(int)$material->id,$qtyBuy,(int)$poLine->uom_id,(int)$material->use_uom_id);}
                        catch(RuntimeException $e){if($this->uoms->code($companyId,(int)$poLine->uom_id)!=='KG'||$gsm<=0||$width<=0)throw $e;$qtyUse=$this->uoms->fromMeters($companyId,(int)$material->use_uom_id,$qtyBuy*1000/($gsm*($width/100)));}
                    }
                    if($qtyUse<=0)throw new RuntimeException('qty_use_actual roll wajib lebih besar dari nol.');
                    $meters=$this->uoms->toMeters($companyId,(int)$material->use_uom_id,$qtyUse);$rate=round($qtyUse/$qtyBuy,6);
                    $roll=$grLine->rolls()->create(['company_id'=>$companyId,'roll_no'=>$r['roll_no'],'material_id'=>$material->id,'use_uom_id'=>$material->use_uom_id,'lot_no'=>$r['lot_no']??null,'shade_group_id'=>$r['shade_group_id']??null,'qty_buy'=>$qtyBuy,'qty_meter_actual'=>$meters,'qty_use_actual'=>$qtyUse,'conversion_rate'=>$rate,'gsm_actual'=>$r['gsm_actual']??null,'width_actual_cm'=>$r['width_actual_cm']??null,'qty_remaining_meter'=>$meters,'qty_remaining_use'=>$qtyUse,'status'=>'QUALITY_HOLD']);
                    $itsLines[]=['material_id'=>$material->id,'warehouse_id'=>$warehouse->id,'location_id'=>$lineData['location_id']??null,'lot_no'=>$roll->lot_no,'roll_id'=>$roll->id,'qty'=>$qtyUse,'uom_id'=>$material->use_uom_id,'unit_cost'=>round(((float)$poLine->unit_price*$qtyBuy)/$qtyUse,6),'source_document_line_id'=>$grLine->id];
                }else $itsLines[]=['material_id'=>$material->id,'warehouse_id'=>$warehouse->id,'location_id'=>$lineData['location_id']??null,'lot_no'=>$lineData['lot_no']??null,'qty'=>$qty,'uom_id'=>$poLine->uom_id,'unit_cost'=>(float)$poLine->unit_price,'source_document_line_id'=>$grLine->id];
                $poLine->received_qty=(float)$poLine->received_qty+$qty;$poLine->save();
            }
            $movement=$this->its->post('PURCHASE_RECEIPT',['company_id'=>$companyId,'source_document_type'=>'goods_receipts','source_document_id'=>$gr->id],$itsLines,$user);
            $gr->update(['status'=>'POSTED','updated_by'=>$user->id]);$po->refreshReceivingStatus();$this->audit->record('create',$gr,after:['doc_no'=>$gr->doc_no,'movement'=>$movement->doc_no]);return $gr->load('lines.rolls');
        });
    }
}
