<?php

namespace Modules\Production\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Core\Support\CurrentCompany;
use Modules\Production\Models\MaterialIssue;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Services\MaterialIssueService;
use Modules\Receiving\Models\FabricRoll;
use RuntimeException;

class MaterialIssueController extends Controller
{
    public function __construct(private MaterialIssueService $service) {}

    public function store(Request $request, ProductionOrder $productionOrder): JsonResponse
    {
        $companyId = CurrentCompany::id();
        $data = $request->validate([
            'warehouse_id' => ['required','integer',Rule::exists('warehouses','id')->where('company_id',$companyId)],
            'lines' => 'required|array|min:1',
            'lines.*.material_id' => ['required','integer',Rule::exists('materials','id')->where('company_id',$companyId)],
            'lines.*.qty' => 'required|numeric|min:0.0001',
            'lines.*.uom_id' => ['required','integer',Rule::exists('uoms','id')->where('company_id',$companyId)],
            'lines.*.roll_id' => ['nullable','integer',Rule::exists('fabric_rolls','id')->where('company_id',$companyId)],
            'lines.*.location_id' => 'nullable|integer|exists:locations,id',
            'lines.*.lot_no' => 'nullable|string|max:64',
        ]);
        return $this->domainResponse(fn () => response()->json($this->service->issue($productionOrder, (int) $data['warehouse_id'], $data['lines'], $request->user()), 201));
    }

    public function backflush(Request $request, ProductionOrder $productionOrder): JsonResponse
    {
        $companyId = CurrentCompany::id();
        $data = $request->validate([
            'warehouse_id' => ['required','integer',Rule::exists('warehouses','id')->where('company_id',$companyId)],
        ]);
        return $this->domainResponse(function () use ($request, $productionOrder, $data): JsonResponse {
            $issue = $this->service->backflush($productionOrder, (int) $data['warehouse_id'], $request->user());
            return response()->json(['data' => $issue, 'message' => $issue ? 'Backflush diposting.' : 'Tidak ada delta backflush.'], $issue ? 201 : 200);
        });
    }

    public function returnLeftover(Request $request, ProductionOrder $productionOrder, FabricRoll $roll): JsonResponse
    {
        $companyId = CurrentCompany::id();
        $data = $request->validate([
            'warehouse_id' => ['required','integer',Rule::exists('warehouses','id')->where('company_id',$companyId)],
            'reason' => 'nullable|string',
        ]);
        return $this->domainResponse(fn () => response()->json($this->service->returnLeftover($productionOrder, $roll, (int) $data['warehouse_id'], $request->user(), $data['reason'] ?? null), 201));
    }

    public function index(Request $request, ProductionOrder $productionOrder): JsonResponse
    {
        return response()->json(MaterialIssue::where('production_order_id', $productionOrder->id)
            ->with('lines.material', 'lines.roll')->orderByDesc('id')->get());
    }

    private function domainResponse(callable $callback): JsonResponse
    {
        try {
            return $callback();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
