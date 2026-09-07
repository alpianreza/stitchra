<?php
namespace Modules\Shipping\Services;
use Illuminate\Support\Facades\DB;use Modules\Core\Models\User;use Modules\Core\Services\AuditService;use Modules\Core\Services\NumberingService;use Modules\Sales\Models\SalesOrder;use Modules\Shipping\Models\CommercialInvoice;use Modules\Shipping\Models\Container;use Modules\Shipping\Models\ExportDocument;use Modules\Shipping\Models\Shipment;use RuntimeException;
class ExportDocumentService
{
 public function __construct(private NumberingService$numbering,private AuditService$audit){}
 public function index(int$c,User$u):array{$this->access($u,$c);return Shipment::withoutGlobalScopes()->with(['salesOrder.customer','packingList','deliverySchedule','lines','containers','commercialInvoice.currency','commercialInvoice.lines','commercialInvoice.arInvoice','exportDocuments'])->where('company_id',$c)->orderByDesc('id')->get()->all();}
 public function createInvoice(Shipment$s,array$d,User$u):CommercialInvoice{return DB::transaction(function()use($s,$d,$u){$ship=Shipment::withoutGlobalScopes()->with('lines')->whereKey($s->id)->lockForUpdate()->firstOrFail();$this->access($u,(int)$ship->company_id);if($ship->status==='CANCELLED')throw new RuntimeException('Commercial Invoice tidak dapat dibuat dari Shipment CANCELLED.');$existing=CommercialInvoice::withoutGlobalScopes()->where('shipment_id',$ship->id)->first();if($existing)return$existing->load('lines','currency');$so=SalesOrder::withoutGlobalScopes()->with(['lines','currency'])->where('company_id',$ship->company_id)->whereKey($ship->sales_order_id)->lockForUpdate()->firstOrFail();if($ship->lines->isEmpty())throw new RuntimeException('Shipment tidak memiliki matrix quantity.');$payload=[];$total=0.0;foreach($ship->lines as$line){$source=$so->lines->first(fn($x)=>(int)$x->style_id===(int)$line->style_id&&(int)$x->colorway_id===(int)$line->colorway_id&&(int)$x->size_id===(int)$line->size_id);if(!$source||(float)$source->price<=0)throw new RuntimeException('Harga SO untuk matrix Commercial Invoice tidak valid.');$amount=round((float)$line->qty_shipped*(float)$source->price,4);$total+=$amount;$payload[]=['shipment_line_id'=>$line->id,'style_id'=>$line->style_id,'colorway_id'=>$line->colorway_id,'size_id'=>$line->size_id,'qty'=>(float)$line->qty_shipped,'unit_price'=>(float)$source->price,'amount'=>$amount];}$invoice=CommercialInvoice::create(['company_id'=>$ship->company_id,'doc_no'=>$this->numbering->next($ship->company_id,'CI'),'shipment_id'=>$ship->id,'sales_order_id'=>$so->id,'invoice_date'=>$d['invoice_date'],'currency_id'=>$so->currency_id,'exchange_rate'=>$so->exchange_rate,'lc_number'=>$d['lc_number']??null,'total_amount'=>round($total,4),'status'=>'DRAFT','created_by'=>$u->id]);foreach($payload as$line)$invoice->lines()->create($line);$this->audit->record('create',$invoice,after:['shipment_id'=>$ship->id,'source'=>'SHIPMENT_MATRIX_X_SO_PRICE','total_amount'=>$invoice->total_amount]);return$invoice->load('lines','currency');});}
    public function issueInvoice(CommercialInvoice $invoice, User $user): CommercialInvoice
    {
        return DB::transaction(function () use ($invoice, $user) {
            // Read persisted ownership; never trust a caller's stale model attributes.
            $source = CommercialInvoice::withoutGlobalScopes()->whereKey($invoice->id)->firstOrFail();
            $shipment = $this->lockIssueShipment((int) $source->shipment_id, (int) $source->company_id, $user);
            $locked = CommercialInvoice::withoutGlobalScopes()->with('lines')
                ->where('company_id', $shipment->company_id)->where('shipment_id', $shipment->id)
                ->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            // A retry must not rewrite historical documents or add another audit entry.
            if ($locked->status === 'ISSUED') return $locked;
            if ($shipment->status === 'CANCELLED') {
                throw new RuntimeException('Commercial Invoice tidak dapat diterbitkan dari Shipment CANCELLED.');
            }
            if ($locked->status !== 'DRAFT' || $locked->lines->isEmpty()) {
                throw new RuntimeException('Hanya Commercial Invoice DRAFT lengkap yang dapat diterbitkan.');
            }
            $locked->update(['status' => 'ISSUED', 'updated_by' => $user->id]);
            $this->audit->record('update', $locked, after: ['status' => 'ISSUED', 'commercial_authority' => 'SHIPMENT_MATRIX_X_SO_PRICE']);
            return $locked->fresh(['lines', 'currency']);
        });
    }
 public function addContainer(Shipment$s,array$d,User$u):Container{return DB::transaction(function()use($s,$d,$u){$ship=Shipment::withoutGlobalScopes()->whereKey($s->id)->lockForUpdate()->firstOrFail();$this->access($u,(int)$ship->company_id);if($ship->status==='CANCELLED')throw new RuntimeException('Container tidak dapat ditambahkan ke Shipment CANCELLED.');$container=Container::create(['company_id'=>$ship->company_id,'shipment_id'=>$ship->id,'container_no'=>$d['container_no'],'size'=>$d['size']??null,'seal_no'=>$d['seal_no']??null,'created_by'=>$u->id]);$this->audit->record('create',$container,after:['shipment_id'=>$ship->id,'container_no'=>$container->container_no]);return$container;});}
 public function addDocument(Shipment$s,array$d,User$u):ExportDocument{return DB::transaction(function()use($s,$d,$u){$ship=Shipment::withoutGlobalScopes()->whereKey($s->id)->lockForUpdate()->firstOrFail();$this->access($u,(int)$ship->company_id);if($ship->status==='CANCELLED')throw new RuntimeException('Export Document tidak dapat ditambahkan ke Shipment CANCELLED.');$doc=ExportDocument::create(['company_id'=>$ship->company_id,'shipment_id'=>$ship->id,'document_type'=>$d['document_type'],'reference_no'=>$d['reference_no']??null,'issue_date'=>$d['issue_date']??null,'file_reference'=>$d['file_reference']??null,'status'=>'DRAFT','created_by'=>$u->id]);$this->audit->record('create',$doc,after:['shipment_id'=>$ship->id,'document_type'=>$doc->document_type]);return$doc;});}
    public function issueDocument(ExportDocument $document, User $user): ExportDocument
    {
        return DB::transaction(function () use ($document, $user) {
            $source = ExportDocument::withoutGlobalScopes()->whereKey($document->id)->firstOrFail();
            $shipment = $this->lockIssueShipment((int) $source->shipment_id, (int) $source->company_id, $user);
            $locked = ExportDocument::withoutGlobalScopes()
                ->where('company_id', $shipment->company_id)->where('shipment_id', $shipment->id)
                ->whereKey($document->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'ISSUED') return $locked;
            if ($shipment->status === 'CANCELLED') {
                throw new RuntimeException('Export Document tidak dapat diterbitkan dari Shipment CANCELLED.');
            }
            if ($locked->status !== 'DRAFT' || (! $locked->reference_no && ! $locked->file_reference)) {
                throw new RuntimeException('Export Document DRAFT memerlukan reference number atau file reference sebelum diterbitkan.');
            }
            $locked->update(['status' => 'ISSUED', 'issue_date' => $locked->issue_date ?? now()->toDateString(), 'updated_by' => $user->id]);
            $this->audit->record('update', $locked, after: ['status' => 'ISSUED']);
            return $locked;
        });
    }

    private function lockIssueShipment(int $shipmentId, int $companyId, User $user): Shipment
    {
        $this->access($user, $companyId);

        // Match createInvoice/addDocument: always lock parent before child.
        // This also serializes issuing against concurrent Shipment status updates.
        return Shipment::withoutGlobalScopes()->where('company_id', $companyId)
            ->whereKey($shipmentId)->lockForUpdate()->firstOrFail();
    }
 private function access(User$u,int$c):void{if((int)$u->company_id!==$c&&!$u->companies()->whereKey($c)->exists())throw new RuntimeException('User tidak memiliki akses ke company export.');}
}
