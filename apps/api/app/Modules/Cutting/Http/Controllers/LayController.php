<?php

namespace Modules\Cutting\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Cutting\Models\CutOrder;
use Modules\Cutting\Models\CutOutput;
use Modules\Cutting\Models\Lay;
use Modules\Cutting\Models\ShadeOverrideRequest;
use Modules\Cutting\Services\LayExecutionService;
use Modules\MasterData\Models\Customer;
use Modules\Receiving\Models\FabricRoll;
use RuntimeException;

class LayController extends Controller
{
    public function __construct(private LayExecutionService $service) {}

    public function buyerRule(Request $request, Customer $customer): JsonResponse
    {
        return $this->domain(fn () => response()->json($this->service->buyerRule($customer, $request->user())));
    }

    public function configureBuyer(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate(['enabled' => 'required|boolean']);
        return $this->domain(fn () => response()->json($this->service->configureBuyer($customer, (bool) $data['enabled'], $request->user())));
    }

    public function index(Request $request, CutOrder $cutOrder): JsonResponse
    {
        return $this->domain(fn () => response()->json(['data' => $this->service->list($cutOrder, $request->user())]));
    }

    public function store(Request $request, CutOrder $cutOrder): JsonResponse
    {
        $data = $request->validate(['layer_count' => 'required|integer|min:1']);
        return $this->domain(fn () => response()->json($this->service->createLay($cutOrder, (int) $data['layer_count'], $request->user()), 201));
    }

    public function addRoll(Request $request, Lay $lay): JsonResponse
    {
        $data = $request->validate(['fabric_roll_id' => 'required|integer', 'qty_used' => 'required|numeric|gt:0']);
        $roll = FabricRoll::withoutGlobalScopes()->findOrFail($data['fabric_roll_id']);
        return $this->domain(fn () => response()->json($this->service->addRoll($lay, $roll, (float) $data['qty_used'], $request->user()), 201));
    }

    public function requestOverride(Request $request, Lay $lay): JsonResponse
    {
        $data = $request->validate(['fabric_roll_id' => 'required|integer', 'qty_used' => 'required|numeric|gt:0', 'reason' => 'required|string|min:3']);
        $roll = FabricRoll::withoutGlobalScopes()->findOrFail($data['fabric_roll_id']);
        return $this->domain(fn () => response()->json($this->service->requestOverride($lay, $roll, (float) $data['qty_used'], $data['reason'], $request->user()), 201));
    }

    public function applyOverride(Request $request, ShadeOverrideRequest $shadeOverrideRequest): JsonResponse
    {
        return $this->domain(fn () => response()->json($this->service->applyOverride($shadeOverrideRequest, $request->user()), 201));
    }

    public function output(Request $request, Lay $lay): JsonResponse
    {
        $data = $request->validate(['cut_order_line_id' => 'required|integer', 'qty_cut' => 'required|numeric|gt:0']);
        return $this->domain(fn () => response()->json($this->service->createOutput($lay, (int) $data['cut_order_line_id'], (float) $data['qty_cut'], $request->user()), 201));
    }

    public function bundles(Request $request, CutOutput $cutOutput): JsonResponse
    {
        $data = $request->validate(['bundle_size' => 'required|integer|min:1']);
        return $this->domain(fn () => response()->json(['data' => $this->service->generateBundles($cutOutput, (int) $data['bundle_size'], $request->user())], 201));
    }

    public function complete(Request $request, Lay $lay): JsonResponse
    {
        return $this->domain(fn () => response()->json($this->service->completeLay($lay, $request->user())));
    }

    private function domain(callable $callback): JsonResponse
    {
        try { return $callback(); }
        catch (RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
    }
}
