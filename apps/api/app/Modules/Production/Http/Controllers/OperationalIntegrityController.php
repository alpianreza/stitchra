<?php

namespace Modules\Production\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Support\CurrentCompany;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Services\OperationalIntegrityService;
use RuntimeException;

class OperationalIntegrityController extends Controller
{
    public function authority(Request $request, OperationalIntegrityService $service): JsonResponse
    {
        return $this->domain(fn () => response()->json($service->authorityMatrix(CurrentCompany::id(), $request->user())));
    }

    public function show(Request $request, ProductionOrder $productionOrder, OperationalIntegrityService $service): JsonResponse
    {
        return $this->domain(fn () => response()->json($service->inspect($productionOrder, $request->user())));
    }

    private function domain(callable $callback): JsonResponse
    {
        try { return $callback(); }
        catch (RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
    }
}
