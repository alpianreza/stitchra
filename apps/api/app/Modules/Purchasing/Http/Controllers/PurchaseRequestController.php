<?php

namespace Modules\Purchasing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Core\Services\AuditService;
use Modules\Core\Support\CurrentCompany;
use Modules\Purchasing\Models\PurchaseRequest;
use Modules\Purchasing\Services\PurchasingService;
use RuntimeException;

class PurchaseRequestController extends Controller
{
    public function __construct(private PurchasingService $service, private AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(PurchaseRequest::STATUSES)],
            'source' => ['nullable', Rule::in(PurchaseRequest::SOURCES)],
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $query = PurchaseRequest::withCount('lines');
        if (! empty($filters['status'])) $query->where('status', $filters['status']);
        if (! empty($filters['source'])) $query->where('source', $filters['source']);
        return response()->json($query->orderByDesc('id')->paginate($filters['per_page'] ?? 25));
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = CurrentCompany::id();
        $data = $request->validate([
            'needed_by' => 'nullable|date', 'notes' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.material_id' => ['required', 'integer', Rule::exists('materials', 'id')->where('company_id', $companyId)],
            'lines.*.qty' => 'required|numeric|min:0.0001',
            'lines.*.uom_id' => ['required', 'integer', Rule::exists('uoms', 'id')->where('company_id', $companyId)],
            'lines.*.need_date' => 'nullable|date',
        ]);

        try {
            $pr = $this->service->createPr($companyId, $data, $data['lines'], 'MANUAL', $request->user());
            $this->audit->record('create', $pr, after: $pr->toArray(), request: $request);
            return response()->json($pr, 201);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function submit(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        try {
            $this->service->submitPr($purchaseRequest, $request->user());
            $this->audit->record('submit', $purchaseRequest, request: $request);
            return response()->json($purchaseRequest->fresh());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
