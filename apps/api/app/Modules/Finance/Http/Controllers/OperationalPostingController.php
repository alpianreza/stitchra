<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Support\CurrentCompany;
use Modules\Finance\Models\Journal;
use Modules\Finance\Services\OperationalPostingService;
use Modules\Receiving\Models\GoodsReceipt;
use RuntimeException;

class OperationalPostingController extends Controller
{
    public function authority(Request $request, OperationalPostingService $service): JsonResponse
    {
        return $this->domain(fn () => response()->json($service->authorityMatrix(CurrentCompany::id(), $request->user())));
    }

    public function postGoodsReceipt(Request $request, GoodsReceipt $goodsReceipt, OperationalPostingService $service): JsonResponse
    {
        return $this->domain(fn () => response()->json($service->postGoodsReceipt($goodsReceipt, $request->user()), 201));
    }

    public function lineage(Request $request, Journal $journal, OperationalPostingService $service): JsonResponse
    {
        return $this->domain(fn () => response()->json($service->journalLineage($journal, $request->user())));
    }

    private function domain(callable $callback): JsonResponse
    {
        try { return $callback(); }
        catch (RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
    }
}
