<?php

namespace Modules\Shipping\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Core\Support\CurrentCompany;
use Modules\Packing\Models\PackingList;
use Modules\Shipping\Models\Shipment;
use Modules\Shipping\Services\ShipmentInventoryValuationService;
use Modules\Shipping\Services\ShipmentService;
use RuntimeException;

class ShipmentController extends Controller
{
    public function __construct(private ShipmentService $service,private ShipmentInventoryValuationService $valuation){}
    public function eligibleFg(Request $request):JsonResponse{return $this->domain(fn()=>response()->json(['data'=>$this->service->eligibleFg(CurrentCompany::id(),$request->user())]));}
    public function index(Request $request):JsonResponse
    {$data=$request->validate(['status'=>['nullable',Rule::in(Shipment::STATUSES)],'per_page'=>'nullable|integer|min:1|max:100']);$query=Shipment::with('salesOrder.customer','packingList');if(!empty($data['status']))$query->where('status',$data['status']);return response()->json($query->orderByDesc('id')->paginate($data['per_page']??25));}
    public function store(Request $request,PackingList $packingList):JsonResponse
    {$data=$request->validate(['ship_date'=>'required|date','forwarder'=>'nullable|string|max:255','booking_no'=>'nullable|string|max:64','container_no'=>'nullable|string|max:64','vessel_flight'=>'nullable|string|max:64','port_of_loading'=>'nullable|string|max:64','port_of_discharge'=>'nullable|string|max:64']);return $this->domain(fn()=>response()->json($this->service->create($packingList,$data,$request->user()),201));}
    public function approveOverTolerance(Request $request,Shipment $shipment):JsonResponse{return $this->domain(fn()=>response()->json($this->service->approveOverTolerance($shipment,$request->user())));}
    public function ship(Request $request,Shipment $shipment):JsonResponse
    {$company=CurrentCompany::id();$data=$request->validate(['fg_warehouse_id'=>['required','integer',Rule::exists('warehouses','id')->where(fn($query)=>$query->where('company_id',$company)->where('type','FG')->where('is_active',true))]]);return $this->domain(fn()=>response()->json($this->valuation->ship($shipment,(int)$data['fg_warehouse_id'],$request->user())));}
    public function lineage(Request $request,Shipment $shipment):JsonResponse{return $this->domain(fn()=>response()->json($this->valuation->lineage($shipment,$request->user())));}
    public function valuation(Request $request,Shipment $shipment):JsonResponse{return $this->domain(fn()=>response()->json($this->valuation->valuation($shipment,$request->user())));}
    public function lineValuation(Request $request,Shipment $shipment,int $line):JsonResponse{return $this->domain(fn()=>response()->json($this->valuation->valuation($shipment,$request->user(),$line)));}
    public function show(Request $request,Shipment $shipment):JsonResponse{return response()->json($shipment->load('lines','salesOrder.customer','packingList.cartons.lines','packingList.productionOrder','packingList.qcInspection'));}
    private function domain(callable $callback):JsonResponse{try{return $callback();}catch(RuntimeException $exception){return response()->json(['message'=>$exception->getMessage()],422);}}
}
