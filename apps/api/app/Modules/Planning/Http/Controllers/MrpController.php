<?php

namespace Modules\Planning\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\AuditService;
use Modules\Core\Support\CurrentCompany;
use Modules\Planning\Models\MrpRun;
use Modules\Planning\Services\MrpService;
use Modules\Purchasing\Services\PurchasingService;

class MrpController extends Controller
{
    public function __construct(
        private MrpService $mrp,
        private PurchasingService $purchasing,
        private AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('planning.mrp.view'), 403);

        return response()->json(
            MrpRun::withCount('requirements')->orderByDesc('run_no')
                ->paginate(min((int) $request->query('per_page', 25), 100))
        );
    }

    /** BR-043/045: jalankan MRP dari SO CONFIRMED — hasil = saran, bukan auto-PO */
    public function run(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('planning.mrp.execute'), 403);

        $data = $request->validate([
            'so_ids' => 'required|array|min:1',
            'so_ids.*' => 'integer|exists:sales_orders,id',
            'horizon_days' => 'nullable|integer|min:1',
            'time_fence_days' => 'nullable|integer|min:0',
        ]);

        $run = $this->mrp->run(CurrentCompany::id(), $data, $request->user());

        $this->audit->record('run', 'mrp_runs', documentId: $run->id, after: [
            'run_no' => $run->run_no, 'so_count' => count($data['so_ids']),
        ], request: $request);

        return response()->json($run, 201);
    }

    public function show(Request $request, MrpRun $mrpRun): JsonResponse
    {
        abort_unless($request->user()->hasPermission('planning.mrp.view'), 403);

        return response()->json($mrpRun->load('requirements.material', 'requirements.uom'));
    }

    /** BR-045/120: planner mengonversi shortage terpilih → PR (source MRP) */
    public function convertToPr(Request $request, MrpRun $mrpRun): JsonResponse
    {
        abort_unless($request->user()->hasPermission('purchasing.pr.create'), 403);

        $data = $request->validate([
            'requirement_ids' => 'required|array|min:1',
            'requirement_ids.*' => 'integer|exists:mrp_requirements,id',
            'needed_by' => 'nullable|date',
        ]);

        $lines = $this->mrp->toPrLines($data['requirement_ids']);

        $pr = $this->purchasing->createPr(
            CurrentCompany::id(),
            ['needed_by' => $data['needed_by'] ?? null],
            $lines,
            'MRP',
            $request->user(),
        );

        $this->mrp->markConverted($data['requirement_ids']);

        $this->audit->record('create', $pr, after: ['doc_no' => $pr->doc_no, 'source' => 'MRP'], request: $request);

        return response()->json($pr, 201);
    }
}
