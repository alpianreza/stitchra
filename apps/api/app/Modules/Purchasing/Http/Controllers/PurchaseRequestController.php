<?php

namespace Modules\Purchasing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\AuditService;
use Modules\Core\Support\CurrentCompany;
use Modules\Purchasing\Models\PurchaseRequest;
use Modules\Purchasing\Services\PurchasingService;

class PurchaseRequestController extends Controller
{
    public function __construct(private PurchasingService $service, private AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('purchasing.pr.view'), 403);

        $query = PurchaseRequest::withCount('lines');
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($source = $request->query('source')) {
            $query->where('source', $source);
        }

        return response()->json($query->orderByDesc('id')->paginate(min((int) $request->query('per_page', 25), 100)));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('purchasing.pr.create'), 403);

        $data = $request->validate([
            'needed_by' => 'nullable|date',
            'notes' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.material_id' => 'required|integer|exists:materials,id',
            'lines.*.qty' => 'required|numeric|min:0.0001',
            'lines.*.uom_id' => 'required|integer|exists:uoms,id',
            'lines.*.need_date' => 'nullable|date',
        ]);

        $pr = $this->service->createPr(CurrentCompany::id(), $data, $data['lines'], 'MANUAL', $request->user());
        $this->audit->record('create', $pr, after: $pr->toArray(), request: $request);

        return response()->json($pr, 201);
    }

    public function submit(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        abort_unless($request->user()->hasPermission('purchasing.pr.submit'), 403);

        $this->service->submitPr($purchaseRequest, $request->user());
        $this->audit->record('submit', $purchaseRequest, request: $request);

        return response()->json($purchaseRequest->fresh());
    }
}
