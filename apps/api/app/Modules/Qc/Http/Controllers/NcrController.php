<?php

namespace Modules\Qc\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Qc\Models\Disposition;
use Modules\Qc\Models\Ncr;
use Modules\Qc\Models\QcInspection;
use Modules\Qc\Services\NcrService;
use RuntimeException;

class NcrController extends Controller
{
    public function __construct(private NcrService $service) {}

    public function index(): JsonResponse
    {
        return response()->json(Ncr::with(['qcInspection:id,doc_no,stage,verdict,cycle', 'productionOrder:id,doc_no', 'dispositions', 'reworkOrders.reinspection:id,doc_no,verdict'])
            ->orderByDesc('id')->paginate(50));
    }

    public function show(Ncr $ncr): JsonResponse
    {
        return response()->json($ncr->load([
            'qcInspection.lines', 'productionOrder', 'dispositions.approver',
            'reworkOrders.bundle', 'reworkOrders.reinspection', 'reworkOrders.records',
        ]));
    }

    public function create(Request $request, QcInspection $qcInspection): JsonResponse
    {
        return $this->domainResponse(fn () => response()->json($this->service->createFromInspection($qcInspection, $request->user()), 201));
    }

    public function addDisposition(Request $request, Ncr $ncr): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(Disposition::ACTIONS)],
            'qty' => 'required|numeric|gt:0',
            'target_stage' => ['nullable', Rule::in(Disposition::TARGET_STAGES)],
            'notes' => 'nullable|string|max:2000',
        ]);
        return $this->domainResponse(fn () => response()->json($this->service->addDisposition($ncr, $data, $request->user()), 201));
    }

    public function submit(Request $request, Ncr $ncr): JsonResponse
    {
        return $this->domainResponse(fn () => response()->json($this->service->submit($ncr, $request->user())));
    }

    private function domainResponse(callable $callback): JsonResponse
    {
        try { return $callback(); }
        catch (RuntimeException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }
}
