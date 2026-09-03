<?php
namespace Modules\Finance\Http\Controllers;
use Illuminate\Http\JsonResponse;use Illuminate\Http\Request;use Illuminate\Routing\Controller;use Modules\Finance\Models\ShipmentCogs;use Modules\Finance\Services\ShipmentCogsService;use Modules\Shipping\Models\Shipment;use RuntimeException;
class ShipmentCogsController extends Controller
{
 public function __construct(private ShipmentCogsService$service){}
 public function post(Request$request,Shipment$shipment):JsonResponse{return$this->domain(fn()=>response()->json($this->service->post($shipment,$request->user()),201));}
 public function shipment(Request$request,Shipment$shipment):JsonResponse{return$this->domain(fn()=>response()->json($this->service->forShipment($shipment,$request->user())));}
 public function show(Request$request,ShipmentCogs$shipmentCogs):JsonResponse{return$this->domain(fn()=>response()->json($this->service->detail($shipmentCogs,$request->user())));}
 public function lineage(Request$request,ShipmentCogs$shipmentCogs):JsonResponse{return$this->domain(fn()=>response()->json($this->service->lineage($shipmentCogs,$request->user())));}
 private function domain(callable$callback):JsonResponse{try{return$callback();}catch(RuntimeException$e){return response()->json(['message'=>$e->getMessage()],422);}}
}
