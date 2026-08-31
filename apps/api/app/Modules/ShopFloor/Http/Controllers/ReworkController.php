<?php

namespace Modules\ShopFloor\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Core\Support\CurrentCompany;
use Modules\ShopFloor\Models\ReworkRecord;
use Modules\ShopFloor\Services\ReworkService;
use RuntimeException;

class ReworkController extends Controller
{
    public function __construct(private ReworkService $service) {}

    public function store(Request $request): JsonResponse
    {
        $companyId = CurrentCompany::id();
        $data = $request->validate([
            'bundle_no'=>'required|string|max:40',
            'operation_id'=>['nullable','integer',Rule::exists('operations','id')->where('company_id',$companyId)],
            'defect_id'=>['required','integer',Rule::exists('defect_library','id')->where(fn ($q) => $q->where('company_id',$companyId)->where('is_active',true))],
            'qty'=>'required|numeric|gt:0','notes'=>'nullable|string|max:2000',
        ]);
        return $this->domainResponse(fn () => response()->json($this->service->record($companyId, $data['bundle_no'], $data, $request->user()), 201));
    }

    public function resolve(Request $request, ReworkRecord $rework): JsonResponse
    {
        return $this->domainResponse(fn () => response()->json($this->service->resolve($rework, $request->user())));
    }

    private function domainResponse(callable $callback): JsonResponse
    {
        try { return $callback(); }
        catch (RuntimeException $e) { return response()->json(['message'=>$e->getMessage()], 422); }
    }
}
