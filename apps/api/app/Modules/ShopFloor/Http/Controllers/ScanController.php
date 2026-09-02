<?php

namespace Modules\ShopFloor\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Core\Support\CurrentCompany;
use Modules\ShopFloor\Services\ScanService;
use RuntimeException;

class ScanController extends Controller
{
    public function __construct(private ScanService $service) {}

    public function scan(Request $request): JsonResponse
    {
        $companyId = CurrentCompany::id();
        $data = $request->validate([
            'bundle_no' => 'required|string|max:40',
            'operation_id' => ['required','integer',Rule::exists('operations','id')->where('company_id',$companyId)],
            'direction' => 'required|in:IN,OUT',
            'stage' => 'required|in:SEWING,FINISHING',
            'line_id' => ['nullable','integer',Rule::exists('lines','id')->where('company_id',$companyId)],
            'employee_id' => ['nullable','integer',Rule::exists('employees','id')->where('company_id',$companyId)],
        ]);

        return $this->domainResponse(fn () => response()->json(
            $this->service->scan($companyId, $data['bundle_no'], $data, $request->user()),
            201,
        ));
    }

    public function eligible(Request $request): JsonResponse
    {
        $data = $request->validate(['production_order_id' => 'nullable|integer|min:1']);

        return $this->domainResponse(fn () => response()->json([
            'data' => $this->service->eligibleBundles(CurrentCompany::id(), $data['production_order_id'] ?? null),
        ]));
    }

    public function lineage(Request $request, string $bundleNo): JsonResponse
    {
        return $this->domainResponse(fn () => response()->json([
            'data' => $this->service->lineage(CurrentCompany::id(), $bundleNo),
        ]));
    }

    public function wip(Request $request, int $productionOrder): JsonResponse
    {
        return $this->domainResponse(fn () => response()->json([
            'data' => $this->service->wipByStage(CurrentCompany::id(), $productionOrder),
        ]));
    }

    public function dailyOutput(Request $request, int $line): JsonResponse
    {
        $data = $request->validate(['date' => 'nullable|date_format:Y-m-d']);

        return $this->domainResponse(fn () => response()->json([
            'data' => $this->service->dailyOutput(CurrentCompany::id(), $line, $data['date'] ?? now()->toDateString()),
        ]));
    }

    private function domainResponse(callable $callback): JsonResponse
    {
        try { return $callback(); }
        catch (RuntimeException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }
}
