<?php

namespace Modules\ProductDev\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\AuditService;
use Modules\Core\Support\CurrentCompany;
use Modules\ProductDev\Models\CostSheet;
use Modules\ProductDev\Services\CostingService;

class CostSheetController extends Controller
{
    public function __construct(private CostingService $service, private AuditService $audit) {}

    /** BR-100: hitung estimated cost dari BOM+Routing APPROVED */
    public function compute(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('pd.costing.create'), 403);

        $data = $request->validate([
            'style_id' => 'required|integer|exists:styles,id',
            'line_id' => 'required|integer|exists:lines,id',
            'period' => 'required|string|max:7',
            'material_prices' => 'required|array|min:1',
            'material_prices.*' => 'numeric|min:0',
        ]);

        $sheet = $this->service->compute(
            styleId: $data['style_id'],
            companyId: CurrentCompany::id(),
            materialPrices: $data['material_prices'],
            lineId: $data['line_id'],
            period: $data['period'],
            creator: $request->user(),
        );

        $this->audit->record('create', $sheet, after: $sheet->toArray(), request: $request);

        return response()->json($sheet, 201);
    }

    public function setPrice(Request $request, CostSheet $costSheet): JsonResponse
    {
        abort_unless($request->user()->hasPermission('pd.costing.update'), 403);

        $data = $request->validate(['fob_price' => 'required|numeric|min:0']);

        return response()->json($this->service->setPrice($costSheet, (float) $data['fob_price']));
    }

    public function submit(Request $request, CostSheet $costSheet): JsonResponse
    {
        abort_unless($request->user()->hasPermission('pd.costing.submit'), 403);

        $this->service->submit($costSheet, $request->user());

        $this->audit->record('submit', $costSheet, request: $request);

        return response()->json($costSheet->fresh());
    }

    public function show(Request $request, CostSheet $costSheet): JsonResponse
    {
        abort_unless($request->user()->hasPermission('pd.costing.view'), 403);

        return response()->json($costSheet->load('lines'));
    }
}
