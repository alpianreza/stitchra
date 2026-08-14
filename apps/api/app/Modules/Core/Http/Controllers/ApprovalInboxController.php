<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Core\Approval\ApprovalEngine;
use Modules\Core\Models\ApprovalRequest;
use Modules\Core\Services\AuditService;
use Modules\Core\Support\CurrentCompany;

/**
 * Kotak masuk approval (BR-015).
 * Otorisasi: request yang tampil/di-approve hanyalah yang step aktifnya cocok
 * dengan role user (assertCanAct di engine) — inheren role-based.
 */
class ApprovalInboxController extends Controller
{
    public function __construct(private ApprovalEngine $engine, private AuditService $audit) {}

    /** Daftar request PENDING yang menunggu aksi role saya */
    public function pending(Request $request): JsonResponse
    {
        $user = $request->user();
        $roleIds = $user->roles()->pluck('roles.id');

        $rows = ApprovalRequest::where('approval_requests.company_id', CurrentCompany::id())
            ->where('approval_requests.status', 'PENDING')
            ->where('approval_requests.is_active', true)
            ->join('approval_flow_steps', function ($j) {
                $j->on('approval_flow_steps.flow_id', '=', 'approval_requests.flow_id')
                  ->whereColumn('approval_flow_steps.step_no', 'approval_requests.current_step');
            })
            ->whereIn('approval_flow_steps.role_id', $roleIds)
            ->join('roles', 'roles.id', '=', 'approval_flow_steps.role_id')
            ->leftJoin('users as submitter', 'submitter.id', '=', 'approval_requests.submitted_by')
            ->select([
                'approval_requests.id', 'approval_requests.doc_type', 'approval_requests.doc_id',
                'approval_requests.current_step', 'approval_requests.submitted_at',
                'roles.name as step_role', 'submitter.name as submitted_by_name',
            ])
            ->orderBy('approval_requests.submitted_at')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function approve(Request $request, int $approvalRequest): JsonResponse
    {
        $data = $request->validate(['note' => 'nullable|string|max:500']);

        try {
            $req = $this->findScoped($approvalRequest);
            $req = $this->engine->approve($req, $request->user(), $data['note'] ?? null);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->audit->record('approve', $req, after: ['status' => $req->status], request: $request);

        return response()->json($req);
    }

    public function reject(Request $request, int $approvalRequest): JsonResponse
    {
        $data = $request->validate(['note' => 'required|string|max:500']);

        try {
            $req = $this->findScoped($approvalRequest);
            $req = $this->engine->reject($req, $request->user(), $data['note']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->audit->record('reject', $req, after: ['status' => 'REJECTED'], request: $request);

        return response()->json($req);
    }

    public function requestRevision(Request $request, int $approvalRequest): JsonResponse
    {
        $data = $request->validate(['note' => 'required|string|max:500']);

        try {
            $req = $this->findScoped($approvalRequest);
            $req = $this->engine->requestRevision($req, $request->user(), $data['note']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->audit->record('revision', $req, after: ['status' => 'REVISION'], request: $request);

        return response()->json($req);
    }

    /** History keputusan untuk satu request (audit trail BR-015) */
    public function show(Request $request, int $approvalRequest): JsonResponse
    {
        $req = $this->findScoped($approvalRequest);

        return response()->json($req->load(['stepInstances', 'submitter', 'flow.steps.role']));
    }

    private function findScoped(int $id): ApprovalRequest
    {
        return ApprovalRequest::where('company_id', CurrentCompany::id())->findOrFail($id);
    }
}
