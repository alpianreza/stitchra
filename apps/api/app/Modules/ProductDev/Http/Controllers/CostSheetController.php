<?php

namespace Modules\ProductDev\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Core\Services\AuditService;
use Modules\Core\Support\CurrentCompany;
use Modules\ProductDev\Models\CostSheet;
use Modules\ProductDev\Services\CostingService;
use RuntimeException;

class CostSheetController extends Controller
{
    public function __construct(private CostingService $service, private AuditService $audit) {}

    public function compute(Request $request): JsonResponse
    {
        $companyId = CurrentCompany::id();
        $tenantExists = fn (string $table) => Rule::exists($table, 'id')->where('company_id', $companyId);

        $data = $request->validate([
            'style_id' => ['required', 'integer', $tenantExists('styles')],
            'line_id' => ['required', 'integer', $tenantExists('lines')],
            'period' => ['required', 'date_format:Y-m'],
            'material_prices' => ['required', 'array', 'min:1'],
            'material_prices.*' => ['numeric', 'gt:0'],
        ]);

        try {
            $sheet = $this->service->compute(
                styleId: $data['style_id'],
                companyId: $companyId,
                materialPrices: $data['material_prices'],
                lineId: $data['line_id'],
                period: $data['period'],
                creator: $request->user(),
            );
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $this->audit->record('create', $sheet, after: $sheet->toArray(), request: $request);

        return response()->json($sheet, 201);
    }

    public function setPrice(Request $request, CostSheet $costSheet): JsonResponse
    {
        $data = $request->validate(['fob_price' => ['required', 'numeric', 'gt:0']]);

        try {
            return response()->json($this->service->setPrice($costSheet, (float) $data['fob_price']));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function submit(Request $request, CostSheet $costSheet): JsonResponse
    {
        try {
            $this->service->submit($costSheet, $request->user());
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $this->audit->record('submit', $costSheet, request: $request);

        return response()->json($costSheet->fresh());
    }

    public function show(Request $request, CostSheet $costSheet): JsonResponse
    {
        return response()->json($costSheet->load('lines'));
    }
}
