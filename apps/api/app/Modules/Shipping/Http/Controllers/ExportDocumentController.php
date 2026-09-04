<?php
namespace Modules\Shipping\Http\Controllers;
use Illuminate\Http\JsonResponse;use Illuminate\Http\Request;use Illuminate\Routing\Controller;use Illuminate\Validation\Rule;use Modules\Core\Support\CurrentCompany;use Modules\Shipping\Models\CommercialInvoice;use Modules\Shipping\Models\ExportDocument;use Modules\Shipping\Models\Shipment;use Modules\Shipping\Services\ExportDocumentService;use RuntimeException;
class ExportDocumentController extends Controller
{
 public function __construct(private ExportDocumentService$service){}
 public function index(Request$r):JsonResponse{return$this->domain(fn()=>response()->json(['data'=>$this->service->index(CurrentCompany::id(),$r->user())]));}
 public function createInvoice(Request$r,Shipment$s):JsonResponse{$d=$r->validate(['invoice_date'=>'required|date_format:Y-m-d','lc_number'=>'nullable|string|max:128']);return$this->domain(fn()=>response()->json($this->service->createInvoice($s,$d,$r->user()),201));}
 public function issueInvoice(Request$r,CommercialInvoice$i):JsonResponse{return$this->domain(fn()=>response()->json($this->service->issueInvoice($i,$r->user())));}
 public function addContainer(Request$r,Shipment$s):JsonResponse{$d=$r->validate(['container_no'=>'required|string|max:64','size'=>'nullable|string|max:32','seal_no'=>'nullable|string|max:64']);return$this->domain(fn()=>response()->json($this->service->addContainer($s,$d,$r->user()),201));}
 public function addDocument(Request$r,Shipment$s):JsonResponse{$d=$r->validate(['document_type'=>['required',Rule::in(ExportDocument::TYPES)],'reference_no'=>'nullable|string|max:128','issue_date'=>'nullable|date_format:Y-m-d','file_reference'=>'nullable|string|max:2048']);return$this->domain(fn()=>response()->json($this->service->addDocument($s,$d,$r->user()),201));}
 public function issueDocument(Request$r,ExportDocument$d):JsonResponse{return$this->domain(fn()=>response()->json($this->service->issueDocument($d,$r->user())));}
 private function domain(callable$c):JsonResponse{try{return$c();}catch(RuntimeException$e){return response()->json(['message'=>$e->getMessage()],422);}}
}
