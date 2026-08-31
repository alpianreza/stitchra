<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Support\CurrentCompany;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Services\ArApService;
use Modules\Purchasing\Models\SupplierInvoice;
use Modules\Shipping\Models\Shipment;
use RuntimeException;

class ArApController extends Controller
{
    public function __construct(private ArApService $service){}
    public function createArInvoice(Request $request,Shipment $shipment):JsonResponse{$data=$request->validate(['due_date'=>'nullable|date_format:Y-m-d']);return $this->domain(fn()=>response()->json($this->service->createArInvoiceFromShipment($shipment,$request->user(),$data['due_date']??null),201));}
    public function payAr(Request $request,ArInvoice $arInvoice):JsonResponse{$data=$this->paymentData($request);return $this->domain(fn()=>response()->json($this->service->recordArPayment($arInvoice,$data,$request->user()),201));}
    public function payAp(Request $request,SupplierInvoice $supplierInvoice):JsonResponse{$data=$this->paymentData($request);return $this->domain(fn()=>response()->json($this->service->recordApPayment($supplierInvoice,$data,$request->user()),201));}
    public function agingAr(Request $request):JsonResponse{$data=$request->validate(['as_of'=>'nullable|date_format:Y-m-d']);return $this->domain(fn()=>response()->json(['data'=>$this->service->agingAr(CurrentCompany::id(),$data['as_of']??now()->toDateString())]));}
    public function agingAp(Request $request):JsonResponse{$data=$request->validate(['as_of'=>'nullable|date_format:Y-m-d']);return $this->domain(fn()=>response()->json(['data'=>$this->service->agingAp(CurrentCompany::id(),$data['as_of']??now()->toDateString())]));}
    private function paymentData(Request $request):array{return $request->validate(['amount'=>'required|numeric|gt:0','payment_date'=>'nullable|date_format:Y-m-d','method'=>'nullable|string|max:32','reference_no'=>'nullable|string|max:64']);}
    private function domain(callable $callback):JsonResponse{try{return $callback();}catch(RuntimeException $e){return response()->json(['message'=>$e->getMessage()],422);}}
}
