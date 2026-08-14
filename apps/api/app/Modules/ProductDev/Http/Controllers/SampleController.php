<?php

namespace Modules\ProductDev\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Core\Support\CurrentCompany;
use Modules\ProductDev\Models\Sample;

class SampleController extends Controller
{
    public function __construct(private NumberingService $numbering, private AuditService $audit) {}

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('pd.sample.create'), 403);

        $data = $request->validate([
            'style_id' => 'required|integer|exists:styles,id',
            'stage' => 'required|in:PROTO,FIT,PP,TOP',
        ]);

        $companyId = CurrentCompany::id();

        $sample = Sample::create([
            'company_id' => $companyId,
            'doc_no' => $this->numbering->next($companyId, 'SMPL'),
            'style_id' => $data['style_id'],
            'stage' => $data['stage'],
            'version' => (int) Sample::where('style_id', $data['style_id'])->where('stage', $data['stage'])->max('version') + 1,
            'buyer_status' => 'PENDING',
            'created_by' => $request->user()->id,
        ]);

        $this->audit->record('create', $sample, after: $sample->toArray(), request: $request);

        return response()->json($sample, 201);
    }

    /** Catat respons buyer (APPROVED/REJECTED/COMMENTED) */
    public function addApproval(Request $request, Sample $sample): JsonResponse
    {
        abort_unless($request->user()->hasPermission('pd.sample.update'), 403);

        $data = $request->validate([
            'status' => 'required|in:APPROVED,REJECTED,COMMENTED',
            'comment' => 'nullable|string',
            'by_name' => 'nullable|string|max:255',
        ]);

        $approval = $sample->approvals()->create($data);
        $sample->update(['buyer_status' => $data['status']]);

        $this->audit->record('update', $sample, after: ['buyer_status' => $data['status']], request: $request);

        return response()->json($approval->fresh(), 201);
    }
}
