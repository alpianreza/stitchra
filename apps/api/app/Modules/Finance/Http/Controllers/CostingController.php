<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Support\CurrentCompany;
use Modules\Finance\Services\ActualCostingService;
use Modules\Finance\Services\BepService;
use Modules\Production\Models\ProductionOrder;

class CostingController extends Controller
{
    /** BR-080/081: actual costing per MO + variance vs standard */
    public function actual(Request $request, ProductionOrder $productionOrder, ActualCostingService $service): JsonResponse
    {
        abort_unless($request->user()->hasPermission('costing.actual.view'), 403);

        try {
            $result = $service->computeForMo($productionOrder, $request->query('period'));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    /** BR-104: BEP per style (Accounting) */
    public function bepStyle(Request $request, int $style, BepService $service): JsonResponse
    {
        abort_unless($request->user()->hasPermission('finance.bep.view'), 403);

        $data = $request->validate(['fixed_cost_share' => 'required|numeric|min:0']);

        try {
            $result = $service->forStyle(CurrentCompany::id(), $style, (float) $data['fixed_cost_share']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    /** BR-104: BEP factory-wide per bulan */
    public function bepFactory(Request $request, BepService $service): JsonResponse
    {
        abort_unless($request->user()->hasPermission('finance.bep.view'), 403);

        $data = $request->validate([
            'period' => 'required|string|max:7',
            'fixed_cost' => 'required|numeric|min:0',
        ]);

        try {
            $result = $service->factoryWide(CurrentCompany::id(), $data['period'], (float) $data['fixed_cost']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }
}
