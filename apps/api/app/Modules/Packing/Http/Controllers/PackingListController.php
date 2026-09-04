<?php

namespace Modules\Packing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Core\Support\CurrentCompany;
use Modules\Packing\Models\PackingList;
use Modules\Packing\Models\PackingSourceAttachment;
use Modules\Packing\Services\PackingService;
use Modules\Qc\Models\QcInspection;
use Modules\Sales\Models\SalesOrder;
use RuntimeException;

class PackingListController extends Controller
{
    public function __construct(private PackingService $service) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(PackingList::STATUSES)], 'per_page' => 'nullable|integer|min:1|max:100']);
        $query = PackingList::with('salesOrder.customer', 'productionOrder', 'qcInspection', 'packingInstruction.lines')->withCount('cartons');
        if (!empty($data['status'])) $query->where('status', $data['status']);
        return response()->json($query->orderByDesc('id')->paginate($data['per_page'] ?? 25));
    }

    public function eligible(): JsonResponse
    {
        return response()->json(['data' => $this->service->eligiblePackingInputs(CurrentCompany::id())]);
    }

    public function store(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        $company = CurrentCompany::id();
        $data = $request->validate(['production_order_id' => ['required', 'integer', Rule::exists('production_orders', 'id')->where('company_id', $company)]]);
        return $this->domain(fn () => response()->json($this->service->create($salesOrder, (int) $data['production_order_id'], $request->user()), 201));
    }

    public function legacySourceCandidates(Request $request, PackingList $packingList): JsonResponse
    {
        return $this->domain(fn () => response()->json(['data' => $this->service->legacySourceCandidates($packingList, $request->user())]));
    }

    public function requestSourceAttachment(Request $request, PackingList $packingList): JsonResponse
    {
        $company = CurrentCompany::id();
        $data = $request->validate([
            'qc_inspection_id' => ['required', 'integer', Rule::exists('qc_inspections', 'id')->where('company_id', $company)],
            'reason' => 'required|string|max:1000',
        ]);
        $source = QcInspection::withoutGlobalScopes()->where('company_id', $company)->findOrFail($data['qc_inspection_id']);
        return $this->domain(fn () => response()->json(
            $this->service->requestLegacySourceAttachment($packingList, $source, $data['reason'], $request->user()),
            201,
        ));
    }

    public function applySourceAttachment(Request $request, PackingSourceAttachment $packingSourceAttachment): JsonResponse
    {
        return $this->domain(fn () => response()->json(
            $this->service->applyLegacySourceAttachment($packingSourceAttachment, $request->user()),
        ));
    }

    public function addCarton(Request $request, PackingList $packingList): JsonResponse
    {
        $company = CurrentCompany::id();
        $data = $request->validate([
            'carton.gross_weight_kg' => 'nullable|numeric|min:0', 'carton.net_weight_kg' => 'nullable|numeric|min:0',
            'carton.dimension' => 'nullable|string|max:32', 'lines' => 'required|array|min:1',
            'lines.*.style_id' => ['required', 'integer', Rule::exists('styles', 'id')->where('company_id', $company)],
            'lines.*.colorway_id' => 'required|integer|exists:colorways,id',
            'lines.*.size_id' => ['required', 'integer', Rule::exists('sizes', 'id')->where('company_id', $company)],
            'lines.*.qty' => 'required|numeric|gt:0',
        ]);
        return $this->domain(fn () => response()->json($this->service->addCarton($packingList, $data['carton'] ?? [], $data['lines'], $request->user()), 201));
    }

    public function finalize(Request $request, PackingList $packingList): JsonResponse
    {
        $company = CurrentCompany::id();
        $data = $request->validate(['fg_warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('company_id', $company)->where('type', 'FG')->where('is_active', true))]]);
        return $this->domain(fn () => response()->json($this->service->finalize($packingList, (int) $data['fg_warehouse_id'], $request->user())));
    }

    public function show(Request $request, PackingList $packingList): JsonResponse
    {
        return response()->json($packingList->load(
            'cartons.lines', 'salesOrder.customer', 'productionOrder', 'qcInspection', 'packingInstruction.lines',
            'sourceAttachments.qcInspection', 'sourceAttachments.approvalRequest', 'shipment',
        ));
    }

    public function lineage(Request $request, PackingList $packingList): JsonResponse
    {
        return response()->json($this->service->lineage($packingList, $request->user()));
    }

    private function domain(callable $callback): JsonResponse
    {
        try { return $callback(); }
        catch (RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
    }
}
