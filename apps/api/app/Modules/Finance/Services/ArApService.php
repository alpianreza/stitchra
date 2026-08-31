<?php

namespace Modules\Finance\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Finance\Models\ApPayment;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Models\ArPayment;
use Modules\Purchasing\Models\SupplierInvoice;
use Modules\Sales\Models\SalesOrder;
use Modules\Shipping\Models\Shipment;
use RuntimeException;

class ArApService
{
    public function __construct(private NumberingService $numbering,private GlPostingService $gl,private AuditService $audit){}

    public function createArInvoiceFromShipment(Shipment $shipment,User $user,?string $dueDate=null):ArInvoice
    {
        return DB::transaction(function()use($shipment,$user,$dueDate){
            $locked=Shipment::withoutGlobalScopes()->with('lines')->whereKey($shipment->id)->lockForUpdate()->firstOrFail();$this->assertAccess($user,(int)$locked->company_id);
            if($locked->status!=='SHIPPED')throw new RuntimeException('AR invoice hanya dari shipment SHIPPED.');
            $existing=ArInvoice::withoutGlobalScopes()->where('shipment_id',$locked->id)->where('status','!=','VOID')->first();if($existing)return $existing->load('lines');
            $so=SalesOrder::withoutGlobalScopes()->with('lines')->where('company_id',$locked->company_id)->whereKey($locked->sales_order_id)->lockForUpdate()->firstOrFail();$rate=(float)($so->exchange_rate??1);if($rate<=0)throw new RuntimeException('Exchange rate AR wajib > 0.');
            $payload=[];$total=0.0;
            foreach($locked->lines as $line){$soLine=$so->lines->first(fn($x)=>(int)$x->style_id===(int)$line->style_id&&(int)$x->colorway_id===(int)$line->colorway_id&&(int)$x->size_id===(int)$line->size_id);if($soLine===null||(float)$soLine->price<=0)throw new RuntimeException('Harga SO matrix shipment tidak valid.');$amount=round((float)$line->qty_shipped*(float)$soLine->price,4);$total+=$amount;$payload[]=['style_id'=>$line->style_id,'description'=>"Shipment {$locked->doc_no} — style #{$line->style_id}",'qty'=>(float)$line->qty_shipped,'unit_price'=>(float)$soLine->price,'amount'=>$amount];}
            if($total<=0)throw new RuntimeException('Total AR invoice wajib > 0.');$invoiceDate=now();$due=$dueDate?Carbon::parse($dueDate):null;if($due&&$due->lt($invoiceDate->copy()->startOfDay()))throw new RuntimeException('Due date AR tidak boleh sebelum invoice date.');
            $invoice=ArInvoice::create(['company_id'=>$locked->company_id,'doc_no'=>$this->numbering->next($locked->company_id,'INV'),'customer_id'=>$so->customer_id,'sales_order_id'=>$so->id,'shipment_id'=>$locked->id,'invoice_date'=>$invoiceDate->toDateString(),'due_date'=>$due?->toDateString(),'currency_id'=>$so->currency_id,'exchange_rate'=>$rate,'total_amount'=>round($total,4),'paid_amount'=>0,'status'=>'OPEN','created_by'=>$user->id]);foreach($payload as $line)$invoice->lines()->create($line);
            $this->gl->postEvent($locked->company_id,'AR_INVOICE','ar_invoices',$invoice->id,round($total*$rate,4),$invoiceDate->format('Y-m'),$user,"AR Invoice {$invoice->doc_no} — {$locked->doc_no}");$this->audit->record('create',$invoice,after:['doc_no'=>$invoice->doc_no,'total'=>$total]);return $invoice->load('lines');
        });
    }

    public function recordArPayment(ArInvoice $invoice,array $data,User $user):ArPayment
    {
        return DB::transaction(function()use($invoice,$data,$user){$locked=ArInvoice::withoutGlobalScopes()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();$this->assertAccess($user,(int)$locked->company_id);if(in_array($locked->status,['PAID','VOID'],true))throw new RuntimeException("Invoice {$locked->doc_no} berstatus {$locked->status}.");$amount=(float)($data['amount']??0);$outstanding=$locked->outstanding();if($amount<=0||$amount>$outstanding+0.0001)throw new RuntimeException("Amount {$amount} tidak valid (outstanding: {$outstanding}).");$date=Carbon::parse($data['payment_date']??now()->toDateString());$payment=ArPayment::create(['company_id'=>$locked->company_id,'doc_no'=>$this->numbering->next($locked->company_id,'PAY'),'ar_invoice_id'=>$locked->id,'payment_date'=>$date->toDateString(),'amount'=>$amount,'method'=>$data['method']??null,'reference_no'=>$data['reference_no']??null,'created_by'=>$user->id]);$locked->paid_amount=(float)$locked->paid_amount+$amount;$locked->status=$locked->outstanding()<=0.0001?'PAID':'PARTIAL';$locked->save();$this->gl->postEvent($locked->company_id,'AR_PAYMENT','ar_payments',$payment->id,round($amount*(float)$locked->exchange_rate,4),$date->format('Y-m'),$user,"Payment {$payment->doc_no} untuk {$locked->doc_no}");$this->audit->record('create',$payment,after:['doc_no'=>$payment->doc_no,'amount'=>$amount]);return $payment;});
    }

    public function recordApPayment(SupplierInvoice $invoice,array $data,User $user):ApPayment
    {
        return DB::transaction(function()use($invoice,$data,$user){$locked=SupplierInvoice::withoutGlobalScopes()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();$this->assertAccess($user,(int)$locked->company_id);if($locked->match_status!=='MATCHED'||!in_array($locked->status,['APPROVED','PAID'],true))throw new RuntimeException("BR-050: invoice {$locked->doc_no} belum MATCHED dan APPROVED.");if($locked->status==='PAID')throw new RuntimeException("Invoice {$locked->doc_no} sudah PAID.");$paid=(float)ApPayment::withoutGlobalScopes()->where('supplier_invoice_id',$locked->id)->sum('amount');$outstanding=(float)$locked->total_amount-$paid;$amount=(float)($data['amount']??0);if($amount<=0||$amount>$outstanding+0.0001)throw new RuntimeException("Amount {$amount} tidak valid (outstanding: {$outstanding}).");$date=Carbon::parse($data['payment_date']??now()->toDateString());$payment=ApPayment::create(['company_id'=>$locked->company_id,'doc_no'=>$this->numbering->next($locked->company_id,'PAY'),'supplier_invoice_id'=>$locked->id,'payment_date'=>$date->toDateString(),'amount'=>$amount,'method'=>$data['method']??null,'reference_no'=>$data['reference_no']??null,'created_by'=>$user->id]);if($amount>=$outstanding-0.0001)$locked->update(['status'=>'PAID','updated_by'=>$user->id]);$this->gl->postEvent($locked->company_id,'AP_PAYMENT','ap_payments',$payment->id,$amount,$date->format('Y-m'),$user,"AP Payment {$payment->doc_no} untuk {$locked->doc_no}");$this->audit->record('create',$payment,after:['doc_no'=>$payment->doc_no,'amount'=>$amount]);return $payment;});
    }

    public function agingAr(int $companyId,string $asOf):array{$invoices=ArInvoice::withoutGlobalScopes()->where('company_id',$companyId)->whereIn('status',['OPEN','PARTIAL'])->get();return $this->bucketize($invoices->map(fn($i)=>['party'=>$i->customer->name??(string)$i->customer_id,'doc_no'=>$i->doc_no,'due_date'=>$i->due_date??$i->invoice_date,'outstanding'=>$i->outstanding()])->all(),$asOf);}
    public function agingAp(int $companyId,string $asOf):array{$invoices=SupplierInvoice::withoutGlobalScopes()->where('company_id',$companyId)->whereIn('status',['DRAFT','SUBMITTED','APPROVED'])->where('match_status','MATCHED')->get();return $this->bucketize($invoices->map(function($i){$paid=(float)ApPayment::withoutGlobalScopes()->where('supplier_invoice_id',$i->id)->sum('amount');return['party'=>$i->supplier->name??(string)$i->supplier_id,'doc_no'=>$i->doc_no,'due_date'=>$i->due_date??$i->invoice_date,'outstanding'=>(float)$i->total_amount-$paid];})->all(),$asOf);}
    private function bucketize(array $items,string $asOf):array{$as=Carbon::createFromFormat('Y-m-d',$asOf)->startOfDay();$b=['current'=>[],'1_30'=>[],'31_60'=>[],'61_90'=>[],'over_90'=>[]];foreach($items as $item){if($item['outstanding']<=0)continue;$due=Carbon::parse($item['due_date'])->startOfDay();$days=$due->lt($as)?$due->diffInDays($as):0;$key=$days===0?'current':($days<=30?'1_30':($days<=60?'31_60':($days<=90?'61_90':'over_90')));$b[$key][]=$item+['days_past_due'=>$days];}return $b;}
    private function assertAccess(User $user,int $companyId):void{if((int)$user->company_id!==$companyId&&!$user->companies()->whereKey($companyId)->exists())throw new RuntimeException('User tidak memiliki akses ke company Finance.');}
}
