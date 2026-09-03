<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Support\CurrentCompany;
use Modules\Finance\Models\FgActualCosting;
use Modules\Finance\Services\ActualCostingService;
use Modules\Finance\Services\BepService;
use Modules\Finance\Services\FgActualCostingService;
use Modules\Production\Models\ProductionOrder;
use RuntimeException;

class CostingController extends Controller
{
    public function actual(Request $request, ProductionOrder $productionOrder, ActualCostingService $service): JsonResponse
    {
        $data=$request->validate(['period'=>['nullable','regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        return $this->domain(fn()=>response()->json($service->computeForMo($productionOrder,$data['period']??null,CurrentCompany::id())));
    }
    public function lineage(Request $request, ProductionOrder $productionOrder, ActualCostingService $service): JsonResponse
    {
        $data=$request->validate(['period'=>['nullable','regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        return $this->domain(fn()=>response()->json($service->lineageForMo($productionOrder,$data['period']??null,CurrentCompany::id())));
    }
    public function d09Calculate(Request $request, ProductionOrder $productionOrder, FgActualCostingService $service): JsonResponse
    {
        $data=$request->validate(['period'=>['required','regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        return $this->domain(fn()=>response()->json($service->calculate($productionOrder,$data['period'],$request->user()),201));
    }
    public function d09Latest(Request $request, ProductionOrder $productionOrder, FgActualCostingService $service): JsonResponse
    { return $this->domain(fn()=>response()->json($service->latest($productionOrder,$request->user()))); }
    public function d09Detail(Request $request, FgActualCosting $costing, FgActualCostingService $service): JsonResponse
    { return $this->domain(fn()=>response()->json($service->detail($costing,$request->user()))); }
    public function d09Finalize(Request $request, FgActualCosting $costing, FgActualCostingService $service): JsonResponse
    { return $this->domain(fn()=>response()->json($service->finalize($costing,$request->user()))); }
    public function bepStyle(Request $request,int $style,BepService $service):JsonResponse
    { $data=$request->validate(['fixed_cost_share'=>'required|numeric|min:0']);return $this->domain(fn()=>response()->json($service->forStyle(CurrentCompany::id(),$style,(float)$data['fixed_cost_share']))); }
    public function bepFactory(Request $request,BepService $service):JsonResponse
    { $data=$request->validate(['period'=>['required','regex:/^\d{4}-(0[1-9]|1[0-2])$/'],'fixed_cost'=>'required|numeric|min:0']);return $this->domain(fn()=>response()->json($service->factoryWide(CurrentCompany::id(),$data['period'],(float)$data['fixed_cost']))); }
    private function domain(callable $callback):JsonResponse
    { try{return $callback();}catch(RuntimeException $exception){return response()->json(['message'=>$exception->getMessage()],422);} }
}
