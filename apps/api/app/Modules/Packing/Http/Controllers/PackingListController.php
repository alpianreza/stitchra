<?php

namespace Modules\Packing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Core\Support\CurrentCompany;
use Modules\Packing\Models\PackingList;
use Modules\Packing\Services\PackingService;
use Modules\Sales\Models\SalesOrder;
use RuntimeException;

class PackingListController extends Controller
{
    public function __construct(private PackingService $service){}
    public function index(Request $request):JsonResponse{$data=$request->validate(['status'=>['nullable',Rule::in(PackingList::STATUSES)],'per_page'=>'nullable|integer|min:1|max:100']);$q=PackingList::with('salesOrder.customer')->withCount('cartons');if(!empty($data['status']))$q->where('status',$data['status']);return response()->json($q->orderByDesc('id')->paginate($data['per_page']??25));}
    public function store(Request $request,SalesOrder $salesOrder):JsonResponse{$company=CurrentCompany::id();$data=$request->validate(['production_order_id'=>['nullable','integer',Rule::exists('production_orders','id')->where('company_id',$company)]]);return $this->domain(fn()=>response()->json($this->service->create($salesOrder,$data['production_order_id']??null,$request->user()),201));}
    public function addCarton(Request $request,PackingList $packingList):JsonResponse{$company=CurrentCompany::id();$data=$request->validate(['carton.gross_weight_kg'=>'nullable|numeric|min:0','carton.net_weight_kg'=>'nullable|numeric|min:0','carton.dimension'=>'nullable|string|max:32','lines'=>'required|array|min:1','lines.*.style_id'=>['required','integer',Rule::exists('styles','id')->where('company_id',$company)],'lines.*.colorway_id'=>'required|integer|exists:colorways,id','lines.*.size_id'=>['required','integer',Rule::exists('sizes','id')->where('company_id',$company)],'lines.*.qty'=>'required|numeric|gt:0']);return $this->domain(fn()=>response()->json($this->service->addCarton($packingList,$data['carton']??[],$data['lines'],$request->user()),201));}
    public function finalize(Request $request,PackingList $packingList):JsonResponse{$company=CurrentCompany::id();$data=$request->validate(['fg_warehouse_id'=>['required','integer',Rule::exists('warehouses','id')->where(fn($q)=>$q->where('company_id',$company)->where('type','FG'))]]);return $this->domain(fn()=>response()->json($this->service->finalize($packingList,(int)$data['fg_warehouse_id'],$request->user())));}
    public function show(Request $request,PackingList $packingList):JsonResponse{return response()->json($packingList->load('cartons.lines','salesOrder.customer'));}
    private function domain(callable $callback):JsonResponse{try{return $callback();}catch(RuntimeException $e){return response()->json(['message'=>$e->getMessage()],422);}}
}
