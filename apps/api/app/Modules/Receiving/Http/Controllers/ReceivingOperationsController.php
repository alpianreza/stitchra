<?php

namespace Modules\Receiving\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Support\CurrentCompany;
use Modules\Receiving\Models\GoodsReceipt;
use Modules\Receiving\Models\Putaway;
use Modules\Receiving\Models\SupplierReturn;
use Modules\Receiving\Services\PutawayService;
use Modules\Receiving\Services\ReceivingTraceService;
use Modules\Receiving\Services\SupplierReturnService;
use RuntimeException;

class ReceivingOperationsController extends Controller
{
    public function __construct(
        private SupplierReturnService $returns,
        private PutawayService $putaways,
        private ReceivingTraceService $trace,
    ) {}

    public function trace(Request $request, GoodsReceipt $goodsReceipt): JsonResponse
    {
        return $this->run(fn () => $this->trace->show($goodsReceipt, $request->user()));
    }

    public function storeReturn(Request $request, GoodsReceipt $goodsReceipt): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:5000', 'lines' => 'required|array|min:1|max:200']);

        return $this->run(fn () => $this->returns->create(CurrentCompany::id(), $goodsReceipt, $data['lines'], $data['reason'], $request->user())->makeHidden('claim_amount'), 201);
    }

    public function postReturn(Request $request, SupplierReturn $supplierReturn): JsonResponse
    {
        return $this->run(fn () => $this->returns->post($supplierReturn, $request->user())->makeHidden('claim_amount'));
    }

    public function cancelReturn(Request $request, SupplierReturn $supplierReturn): JsonResponse
    {
        return $this->run(fn () => $this->returns->cancel($supplierReturn, $request->user())->makeHidden('claim_amount'));
    }

    public function storePutaway(Request $request, GoodsReceipt $goodsReceipt): JsonResponse
    {
        $data = $request->validate(['notes' => 'nullable|string|max:5000', 'lines' => 'required|array|min:1|max:200']);

        return $this->run(fn () => $this->putaways->create(CurrentCompany::id(), $goodsReceipt, $data['lines'], $data['notes'] ?? null, $request->user()), 201);
    }

    public function postPutaway(Request $request, Putaway $putaway): JsonResponse
    {
        return $this->run(fn () => $this->putaways->post($putaway, $request->user()));
    }

    public function cancelPutaway(Request $request, Putaway $putaway): JsonResponse
    {
        return $this->run(fn () => $this->putaways->cancel($putaway, $request->user()));
    }

    private function run(callable $callback, int $status = 200): JsonResponse
    {
        try {
            return response()->json($callback(), $status);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
