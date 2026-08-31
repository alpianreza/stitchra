<?php

namespace Modules\Planning\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Core\Services\AuditService;
use Modules\Core\Support\CurrentCompany;
use Modules\Planning\Models\MrpRun;
use Modules\Planning\Services\MrpService;
use Modules\Purchasing\Services\PurchasingService;
use RuntimeException;

class MrpController extends Controller
{
    public function __construct(private MrpService $mrp, private PurchasingService $purchasing, private AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json(MrpRun::withCount('requirements')->orderByDesc('run_no')->paginate($filters['per_page'] ?? 25));
    }

    public function run(Request $request): JsonResponse
    {
        $companyId = CurrentCompany::id();
        $data = $request->validate([
            'so_ids' => 'required|array|min:1',
            'so_ids.*' => ['integer','distinct',Rule::exists('sales_orders','id')->where('company_id',$companyId)->where('status','CONFIRMED')],
            'horizon_days' => 'nullable|integer|min:1|max:3650',
            'time_fence_days' => 'nullable|integer|min:0|max:3650',
        ]);
        try {
            $run = $this->mrp->run($companyId, $data, $request->user());
            $this->audit->record('run', 'mrp_runs', documentId: $run->id, after: ['run_no' => $run->run_no, 'so_count' => count($data['so_ids'])], request: $request);
            return response()->json($run, 201);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function show(Request $request, MrpRun $mrpRun): JsonResponse
    {
        return response()->json($mrpRun->load('requirements.material', 'requirements.uom'));
    }

    public function convertToPr(Request $request, MrpRun $mrpRun): JsonResponse
    {
        $data = $request->validate([
            'requirement_ids' => 'required|array|min:1',
            'requirement_ids.*' => 'integer|distinct|exists:mrp_requirements,id',
            'needed_by' => 'nullable|date',
        ]);

        try {
            $pr = DB::transaction(function () use ($data, $mrpRun, $request) {
                $lines = $this->mrp->toPrLines($data['requirement_ids'], $mrpRun);
                $pr = $this->purchasing->createPr(CurrentCompany::id(), ['needed_by' => $data['needed_by'] ?? null], $lines, 'MRP', $request->user());
                $this->mrp->markConverted($data['requirement_ids'], $mrpRun);
                return $pr;
            });
            $this->audit->record('create', $pr, after: ['doc_no' => $pr->doc_no, 'source' => 'MRP'], request: $request);
            return response()->json($pr, 201);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
