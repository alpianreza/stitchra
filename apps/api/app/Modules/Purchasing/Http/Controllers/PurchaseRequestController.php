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
