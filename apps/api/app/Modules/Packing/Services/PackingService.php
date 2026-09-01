<?php

namespace Modules\Packing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\MasterData\Models\Uom;
use Modules\Packing\Models\Carton;
use Modules\Packing\Models\PackingList;
use Modules\Production\Models\ProductionOrder;
use Modules\Qc\Models\QcInspection;
use Modules\Sales\Models\SalesOrder;
use RuntimeException;

class PackingService
{
    public function __construct(private NumberingService $numbering,private InventoryTransactionService $its,private AuditService $audit){}

    public function create(SalesOrder $so,?int $moId,User $user): PackingList
    {
        return DB::transaction(function()use($so,$moId,$user){
            $locked=SalesOrder::withoutGlobalScopes()->whereKey($so->id)->lockForUpdate()->firstOrFail();
            $this->assertAccess($user,(int)$locked->company_id);
            if(!in_array($locked->status,['CONFIRMED','IN_PROGRESS'],true))throw new RuntimeException('Packing list hanya untuk SO CONFIRMED/IN_PROGRESS.');
            if($moId!==null&&!ProductionOrder::withoutGlobalScopes()->where('company_id',$locked->company_id)->where('sales_order_id',$locked->id)->whereKey($moId)->exists())throw new RuntimeException('MO packing bukan milik SO/company ini.');
            return PackingList::create(['company_id'=>$locked->company_id,'doc_no'=>$this->numbering->next($locked->company_id,'PL'),'sales_order_id'=>$locked->id,'production_order_id'=>$moId,'status'=>'DRAFT','created_by'=>$user->id]);
        });
    }

    public function addCarton(PackingList $pl,array $carton,array $lines,User $user): Carton
    {
        if($lines===[])throw new RuntimeException('Karton wajib punya isi.');
        return DB::transaction(function()use($pl,$carton,$lines,$user){
            $locked=PackingList::withoutGlobalScopes()->whereKey($pl->id)->lockForUpdate()->firstOrFail();
            $this->assertAccess($user,(int)$locked->company_id);
            if($locked->status!=='DRAFT')throw new RuntimeException('Karton hanya bisa ditambah ke packing list DRAFT.');
            $seen=[];
            foreach($lines as $line){
                $qty=(float)($line['qty']??0);$key=((int)$line['style_id']).'-'.((int)$line['colorway_id']).'-'.((int)$line['size_id']);
                if($qty<=0)throw new RuntimeException('Qty carton wajib > 0.');
                if(isset($seen[$key]))throw new RuntimeException('Matrix carton tidak boleh duplikat.');$seen[$key]=true;
                if(!DB::table('sales_order_lines')->where('sales_order_id',$locked->sales_order_id)->where('style_id',$line['style_id'])->where('colorway_id',$line['colorway_id'])->where('size_id',$line['size_id'])->exists())throw new RuntimeException('Matrix carton tidak terdapat pada SO.');
                if($locked->production_order_id&&!ProductionOrder::withoutGlobalScopes()->whereKey($locked->production_order_id)->where('style_id',$line['style_id'])->exists())throw new RuntimeException('Style carton tidak sesuai MO packing.');
            }
            $gross=$carton['gross_weight_kg']??null;$net=$carton['net_weight_kg']??null;
            if(($gross!==null&&(float)$gross<0)||($net!==null&&(float)$net<0)||($gross!==null&&$net!==null&&(float)$net>(float)$gross))throw new RuntimeException('Berat carton tidak valid.');
            $seq=(int)$locked->cartons()->max('seq')+1;
            $created=$locked->cartons()->create(['company_id'=>$locked->company_id,'carton_no'=>$locked->doc_no.'-'.str_pad((string)$seq,4,'0',STR_PAD_LEFT),'seq'=>$seq,'gross_weight_kg'=>$gross,'net_weight_kg'=>$net,'dimension'=>$carton['dimension']??null]);
            foreach($lines as $line)$created->lines()->create($line);
            return $created->load('lines');
        });
    }

    public function finalize(PackingList $pl,int $warehouseId,User $user): PackingList
    {
        return DB::transaction(function()use($pl,$warehouseId,$user){
            $locked=PackingList::withoutGlobalScopes()->whereKey($pl->id)->lockForUpdate()->firstOrFail();
            $this->assertAccess($user,(int)$locked->company_id);
            if($locked->status!=='DRAFT'||!$locked->cartons()->exists())throw new RuntimeException('Packing list harus DRAFT dan memiliki karton.');
            $so=SalesOrder::withoutGlobalScopes()->with('lines','customer')->where('company_id',$locked->company_id)->whereKey($locked->sales_order_id)->lockForUpdate()->firstOrFail();
            if(!DB::table('warehouses')->where('company_id',$locked->company_id)->where('type','FG')->where('id',$warehouseId)->exists())throw new RuntimeException('Warehouse finalize wajib warehouse FG pada company yang sama.');
            $pcs=Uom::withoutGlobalScopes()->where('company_id',$locked->company_id)->where('code','PCS')->first();
            if($pcs===null)throw new RuntimeException('PCS UOM belum dikonfigurasi pada company ini.');
            $mo=null;
            if($locked->production_order_id){
                $mo=ProductionOrder::withoutGlobalScopes()->where('company_id',$locked->company_id)->where('sales_order_id',$so->id)->whereKey($locked->production_order_id)->lockForUpdate()->firstOrFail();
                $pass=QcInspection::withoutGlobalScopes()->where('company_id',$locked->company_id)->where('production_order_id',$mo->id)->where('stage','FINAL')->where('verdict','PASS')->orderByDesc('cycle')->first();
                if($pass===null||$mo->status!=='QC')throw new RuntimeException('BR-082: QC FINAL PASS dan status MO QC wajib sebelum finalize packing.');
            }
            $packed=DB::table('carton_lines')->join('cartons','cartons.id','=','carton_lines.carton_id')->where('cartons.packing_list_id',$locked->id)->selectRaw('style_id,colorway_id,size_id,SUM(qty) qty')->groupBy('style_id','colorway_id','size_id')->get();
            $tolerance=(float)($so->tolerance_pct??$so->customer?->shipment_tolerance_pct??0);
            foreach($packed as $row){
                $ordered=(float)$so->lines->first(fn($l)=>(int)$l->style_id===(int)$row->style_id&&(int)$l->colorway_id===(int)$row->colorway_id&&(int)$l->size_id===(int)$row->size_id)?->qty;
                $prior=(float)DB::table('carton_lines')->join('cartons','cartons.id','=','carton_lines.carton_id')->join('packing_lists','packing_lists.id','=','cartons.packing_list_id')->where('packing_lists.sales_order_id',$so->id)->where('packing_lists.status','APPROVED')->where('carton_lines.style_id',$row->style_id)->where('carton_lines.colorway_id',$row->colorway_id)->where('carton_lines.size_id',$row->size_id)->sum('carton_lines.qty');
                if($ordered<=0||$prior+(float)$row->qty-$ordered*(1+$tolerance/100)>0.0001)throw new RuntimeException('BR-021: cumulative packed quantity melebihi SO+toleransi.');
            }
            $currentTotal=(float)$packed->sum('qty');
            if($mo){
                $priorMo=(float)DB::table('carton_lines')->join('cartons','cartons.id','=','carton_lines.carton_id')->join('packing_lists','packing_lists.id','=','cartons.packing_list_id')->where('packing_lists.production_order_id',$mo->id)->where('packing_lists.status','APPROVED')->sum('carton_lines.qty');
                if($priorMo+$currentTotal-(float)$mo->qty_produced>0.0001)throw new RuntimeException('Packed quantity melebihi qty produced MO.');
            }
            $itsLines=$packed->map(fn($r)=>['item_type'=>'FG','style_id'=>$r->style_id,'colorway_id'=>$r->colorway_id,'size_id'=>$r->size_id,'warehouse_id'=>$warehouseId,'qty'=>(float)$r->qty,'uom_id'=>$pcs->id])->all();
            $this->its->post('PRODUCTION_RECEIPT',['company_id'=>$locked->company_id,'source_document_type'=>'packing_lists','source_document_id'=>$locked->id],$itsLines,$user);
            $locked->update(['status'=>'APPROVED','updated_by'=>$user->id]);
            if($mo&&$priorMo+$currentTotal+0.0001>=(float)$mo->qty_produced)$mo->update(['status'=>'PACKED','updated_by'=>$user->id]);
            $this->audit->record('update',$locked,after:['status'=>'APPROVED','cartons'=>$locked->cartons()->count()]);
            return $locked->fresh('cartons.lines');
        });
    }

    private function assertAccess(User $user,int $companyId):void{if((int)$user->company_id!==$companyId&&!$user->companies()->whereKey($companyId)->exists())throw new RuntimeException('User tidak memiliki akses ke company packing.');}
}
