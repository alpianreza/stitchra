<?php

namespace Modules\Qc\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Core\Support\CurrentCompany;
use Modules\Production\Models\ProductionOrder;
use Modules\Qc\Models\QcInspection;
use Modules\Qc\Services\QcService;
use RuntimeException;

class QcInspectionController extends Controller
{
    public function __construct(private QcService $service) {}

    public function index(Request $request, ProductionOrder $productionOrder): JsonResponse
    {
        return response()->json(QcInspection::where('production_order_id',$productionOrder->id)->withCount('lines')->orderByDesc('id')->get());
    }
    public function store(Request $request, ProductionOrder $productionOrder): JsonResponse
    {
        $data=$request->validate(['stage'=>['required',Rule::in(QcInspection::STAGES)],'lot_qty'=>'required|numeric|gt:0']);
        return $this->domainResponse(fn()=>response()->json($this->service->create($productionOrder,$data['stage'],(float)$data['lot_qty'],$request->user()),201));
    }
    public function recordDefects(Request $request, QcInspection $qcInspection): JsonResponse
    {
        $companyId=CurrentCompany::id();
        $data=$request->validate([
            'defects'=>'required|array|min:1','defects.*.defect_id'=>['required','integer',Rule::exists('defect_library','id')->where(fn($q)=>$q->where('company_id',$companyId)->where('is_active',true))],
            'defects.*.qty'=>'nullable|integer|min:1','defects.*.bundle_id'=>['nullable','integer',Rule::exists('bundles','id')->where('company_id',$companyId)],
            'defects.*.operation_id'=>['nullable','integer',Rule::exists('operations','id')->where('company_id',$companyId)],
            'defects.*.notes'=>'nullable|string|max:2000','defects.*.photo_path'=>'nullable|string|max:1024',
        ]);
        return $this->domainResponse(fn()=>response()->json($this->service->recordDefects($qcInspection,$data['defects'],$request->user())));
    }
    public function finalize(Request $request, QcInspection $qcInspection): JsonResponse
    {
        $data=$request->validate(['verdict'=>'nullable|in:PASS,FAIL']);
        return $this->domainResponse(fn()=>response()->json($this->service->finalize($qcInspection,$request->user(),$data['verdict']??null)));
    }
    private function domainResponse(callable $callback): JsonResponse
    {
        try{return $callback();}catch(RuntimeException $e){return response()->json(['message'=>$e->getMessage()],422);}
    }
}
