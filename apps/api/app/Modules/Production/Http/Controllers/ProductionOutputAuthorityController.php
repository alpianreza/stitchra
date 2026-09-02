<?php

namespace Modules\Production\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Services\ProductionOutputAuthorityService;
use RuntimeException;

class ProductionOutputAuthorityController extends Controller
{
    public function show(Request $request, ProductionOrder $productionOrder, ProductionOutputAuthorityService $service): JsonResponse
    {
        try {
            return response()->json($service->inspect($productionOrder, $request->user()));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
