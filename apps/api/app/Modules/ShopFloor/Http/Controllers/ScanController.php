<?php

namespace Modules\ShopFloor\Http\Controllers;

use Illuminate\Http\JsonResponse;use Illuminate\Http\Request;use Illuminate\Routing\Controller;use Illuminate\Validation\Rule;use Modules\Core\Support\CurrentCompany;use Modules\ShopFloor\Services\ScanService;use RuntimeException;
class ScanController extends Controller
{
 public function __construct(private ScanService$service){}
 public function scan(Request$request):JsonResponse{$c=CurrentCompany::id();$d=$request->validate(['bundle_no'=>'required|string|max:40','operation_id'=>['required','integer',Rule::exists('operations','id')->where('company_id',$c)],'direction'=>'required|in:IN,OUT','stage'=>'required|in:SEWING,FINISHING','line_id'=>['nullable','integer',Rule::exists('lines','id')->where('company_id',$c)],'employee_id'=>['nullable','integer',Rule::exists('employees','id')->where('company_id',$c)]]);return$this->domain(fn()=>response()->json($this->service->scan($c,$d['bundle_no'],$d,$request->user()),201));}
 public function eligible(Request$request):JsonResponse{$d=$request->validate(['production_order_id'=>'nullable|integer|min:1']);return$this->domain(fn()=>response()->json(['data'=>$this->service->eligibleBundles(CurrentCompany::id(),$d['production_order_id']??null)]));}
 public function lineage(Request$request,string$bundleNo):JsonResponse{return$this->domain(fn()=>response()->json(['data'=>$this->service->lineage(CurrentCompany::id(),$bundleNo)]));}
 public function wip(Request$request,int$productionOrder):JsonResponse{return$this->domain(fn()=>response()->json(['data'=>$this->service->wipByStage(CurrentCompany::id(),$productionOrder)]));}
 public function dailyOutput(Request$request,int$line):JsonResponse{$d=$request->validate(['date'=>'nullable|date_format:Y-m-d']);return$this->domain(fn()=>response()->json(['data'=>$this->service->dailyOutput(CurrentCompany::id(),$line,$d['date']??now()->toDateString())]));}
 private function domain(callable$callback):JsonResponse{try{return$callback();}catch(RuntimeException$e){return response()->json(['message'=>$e->getMessage()],422);}}
}
