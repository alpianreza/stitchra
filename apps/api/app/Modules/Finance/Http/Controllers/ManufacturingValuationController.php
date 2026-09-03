<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Support\CurrentCompany;
use Modules\Finance\Models\ActualCostFreeze;
use Modules\Finance\Models\MoValuationEligibility;
use Modules\Finance\Models\ValuationAllocationProfile;
use Modules\Finance\Services\ManufacturingValuationService;
use Modules\Production\Models\ProductionOrder;
use RuntimeException;

class ManufacturingValuationController extends Controller
{
    public function createProfile(Request $request, ManufacturingValuationService $service): JsonResponse
    {
        $data=$request->validate(['code'=>'required|string|max:64','version'=>'required|integer|min:1','effective_date'=>'required|date',
            'rules'=>'required|array|size:12','rules.*.component'=>'required|string','rules.*.stage'=>'required|string','rules.*.allocation_rule'=>'required|string|max:32',
            'rules.*.allocation_value'=>'required|numeric|min:0|max:1','rules.*.allocation_mode'=>'nullable|string','rules.*.source_structure'=>'nullable|array']);
        return $this->domain(fn()=>response()->json($service->createProfile(CurrentCompany::id(),$data,$request->user()),201));
    }
    public function activateProfile(Request $request, ValuationAllocationProfile $profile, ManufacturingValuationService $service): JsonResponse
    { return $this->domain(fn()=>response()->json($service->activateProfile($profile,$request->user()))); }
    public function createEligibility(Request $request, ProductionOrder $productionOrder, ManufacturingValuationService $service): JsonResponse
    {
        $data=$request->validate(['allocation_profile_id'=>'required|integer','effective_date'=>'required|date']);
        $profile=ValuationAllocationProfile::withoutGlobalScopes()->where('company_id',CurrentCompany::id())->findOrFail($data['allocation_profile_id']);
        return $this->domain(fn()=>response()->json($service->createEligibility($productionOrder,$profile,$data['effective_date'],$request->user()),201));
    }
    public function activateEligibility(Request $request, MoValuationEligibility $eligibility, ManufacturingValuationService $service): JsonResponse
    { return $this->domain(fn()=>response()->json($service->activateEligibility($eligibility,$request->user()))); }
    public function valueWip(Request $request, ProductionOrder $productionOrder, int $transfer, ManufacturingValuationService $service): JsonResponse
    { return $this->domain(fn()=>response()->json($service->valueWipTransfer($productionOrder,$transfer,$request->user()))); }
    public function valueFg(Request $request, ProductionOrder $productionOrder, int $movement, ManufacturingValuationService $service): JsonResponse
    { return $this->domain(fn()=>response()->json($service->valueFgReceipt($productionOrder,$movement,$request->user()))); }
    public function createFreeze(Request $request, ProductionOrder $productionOrder, ManufacturingValuationService $service): JsonResponse
    {
        $data=$request->validate(['period'=>['required','regex:/^\d{4}-(0[1-9]|1[0-2])$/'],'other_amount'=>'nullable|numeric','other_source'=>'nullable|string|max:500']);
        return $this->domain(fn()=>response()->json($service->createFreeze($productionOrder,$data['period'],$data['other_amount']??null,$data['other_source']??null,$request->user()),201));
    }
    public function applyFreeze(Request $request, ActualCostFreeze $freeze, ManufacturingValuationService $service): JsonResponse
    { return $this->domain(fn()=>response()->json($service->applyFreeze($freeze,$request->user()))); }
    public function show(Request $request, ProductionOrder $productionOrder, ManufacturingValuationService $service): JsonResponse
    { return $this->domain(fn()=>response()->json($service->status($productionOrder,$request->user()))); }
    private function domain(callable $callback): JsonResponse
    { try{return $callback();}catch(RuntimeException $exception){return response()->json(['message'=>$exception->getMessage()],422);} }
}
