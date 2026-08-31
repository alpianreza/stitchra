<?php

namespace Modules\Subcon\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Core\Support\CurrentCompany;
use Modules\Production\Models\ProductionOrder;
use Modules\Subcon\Models\SubconOrder;
use Modules\Subcon\Services\SubconService;
use RuntimeException;

class SubconOrderController extends Controller
{
    public function __construct(private SubconService $service){}
    public function index(Request $request):JsonResponse{$data=$request->validate(['status'=>['nullable',Rule::in(SubconOrder::STATUSES)],'per_page'=>'nullable|integer|min:1|max:100']);$q=SubconOrder::with('supplier','productionOrder');if(!empty($data['status']))$q->where('status',$data['status']);return response()->json($q->orderByDesc('id')->paginate($data['per_page']??25));}
    public function store(Request $request,ProductionOrder $productionOrder):JsonResponse{$company=CurrentCompany::id();$data=$request->validate(['supplier_id'=>['required','integer',Rule::exists('suppliers','id')->where(fn($q)=>$q->where('company_id',$company)->where('type','SUBCON'))],'operation_id'=>['nullable','integer',Rule::exists('operations','id')->where('company_id',$company)],'expected_return'=>'nullable|date','fee_per_pcs'=>'required|numeric|min:0','warehouse_id'=>['required','integer',Rule::exists('warehouses','id')->where('company_id',$company)],'lines'=>'required|array|min:1','lines.*.material_id'=>['nullable','integer',Rule::exists('materials','id')->where('company_id',$company)],'lines.*.bundle_id'=>['nullable','integer',Rule::exists('bundles','id')->where('company_id',$company)],'lines.*.qty_sent'=>'required|numeric|gt:0','lines.*.uom_id'=>['nullable','integer',Rule::exists('uoms','id')->where('company_id',$company)]]);return $this->domain(fn()=>response()->json($this->service->createAndSend($company,$productionOrder,(int)$data['supplier_id'],$data['lines'],$data,$request->user()),201));}
    public function receive(Request $request,SubconOrder $subconOrder):JsonResponse{$company=CurrentCompany::id();$data=$request->validate(['returns'=>'required|array|min:1','returns.*.line_id'=>'required|integer|exists:subcon_order_lines,id','returns.*.qty_returned'=>'required|numeric|gt:0','returns.*.warehouse_id'=>['required','integer',Rule::exists('warehouses','id')->where('company_id',$company)]]);return $this->domain(fn()=>response()->json($this->service->receive($subconOrder,$data['returns'],$request->user())));}
    public function show(Request $request,SubconOrder $subconOrder):JsonResponse{return response()->json($subconOrder->load('lines','fees','supplier'));}
    private function domain(callable $callback):JsonResponse{try{return $callback();}catch(RuntimeException $e){return response()->json(['message'=>$e->getMessage()],422);}}
}
