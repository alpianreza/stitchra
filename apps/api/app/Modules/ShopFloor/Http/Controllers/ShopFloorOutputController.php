<?php

namespace Modules\ShopFloor\Http\Controllers;

use Illuminate\Http\JsonResponse;use Illuminate\Http\Request;use Illuminate\Routing\Controller;use Modules\Core\Support\CurrentCompany;use Modules\ShopFloor\Services\ShopFloorOutputService;use RuntimeException;
class ShopFloorOutputController extends Controller
{
 public function __construct(private ShopFloorOutputService$service){}
 public function eligibleFinishing(Request$request):JsonResponse{$d=$request->validate(['production_order_id'=>'nullable|integer|min:1']);return response()->json(['data'=>$this->service->eligibleFinishing(CurrentCompany::id(),$d['production_order_id']??null)]);}
 public function completeFinishing(Request$request,string$bundleNo):JsonResponse{return$this->domain(fn()=>response()->json($this->service->completeFinishing(CurrentCompany::id(),$bundleNo,$request->user()),201));}
 public function daily(Request$request):JsonResponse{$d=$request->validate(['line_id'=>'nullable|integer|min:1','date'=>'nullable|date_format:Y-m-d']);return response()->json(['data'=>$this->service->daily(CurrentCompany::id(),$d['line_id']??null,$d['date']??null)]);}
 public function finishing(Request$request):JsonResponse{$d=$request->validate(['production_order_id'=>'nullable|integer|min:1']);return response()->json(['data'=>$this->service->finishing(CurrentCompany::id(),$d['production_order_id']??null)]);}
 private function domain(callable$f):JsonResponse{try{return$f();}catch(RuntimeException$e){return response()->json(['message'=>$e->getMessage()],422);}}
}
