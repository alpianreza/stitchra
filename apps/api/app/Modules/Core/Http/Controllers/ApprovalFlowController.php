<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\ApprovalFlow;
use Modules\Core\Models\Role;
use Modules\Core\Services\AuditService;
use Modules\Core\Support\CurrentCompany;

/**
 * Setup approval flow per doc_type (BR-015).
 * Otorisasi: permission core.approval.manage (super_admin sebagai break-glass fallback).
 * Versi baru per doc_type otomatis menonaktifkan versi aktif sebelumnya.
 */
class ApprovalFlowController extends Controller
{
    public const DOC_TYPES = ['SO', 'PR', 'PO', 'BOM', 'ROUTING', 'COST', 'MO', 'ADJ', 'OPN'];

    public function __construct(private AuditService $audit) {}

    public function roles(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        return response()->json([
            'data' => Role::select('id', 'code', 'name')->orderBy('name')->get(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $query = ApprovalFlow::with('steps.role');
        if ($docType = $request->query('doc_type')) {
            $query->where('doc_type', $docType);
        }

        return response()->json(['data' => $query->orderBy('doc_type')->orderByDesc('version')->get()]);
    }

    /** Buat versi flow baru untuk doc_type; versi aktif lama dinonaktifkan (BR-015 versioning) */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $data = $request->validate([
            'doc_type' => 'required|string|in:'.implode(',', self::DOC_TYPES),
            'mode' => 'required|in:sequential',
            'steps' => 'required|array|min:1',
            'steps.*.role_id' => 'required|integer|exists:roles,id',
            'steps.*.min_value' => 'nullable|numeric|min:0',
            'steps.*.max_value' => 'nullable|numeric|min:0',
        ]);

        $companyId = CurrentCompany::id();

        $flow = DB::transaction(function () use ($companyId, $data, $request): ApprovalFlow {
            $nextVersion = (int) ApprovalFlow::where('company_id', $companyId)
                ->where('doc_type', $data['doc_type'])->max('version') + 1;

            // Versi lama nonaktif — hanya satu flow aktif per doc_type
            ApprovalFlow::where('company_id', $companyId)
                ->where('doc_type', $data['doc_type'])
                ->where('is_active', true)
                ->update(['is_active' => false]);

            $flow = ApprovalFlow::create([
                'company_id' => $companyId,
                'doc_type' => $data['doc_type'],
                'version' => $nextVersion,
                'mode' => $data['mode'],
                'is_active' => true,
                'created_by' => $request->user()->id,
            ]);

            foreach ($data['steps'] as $i => $step) {
                $flow->steps()->create([
                    'step_no' => $i + 1,
                    'role_id' => $step['role_id'],
                    'min_value' => $step['min_value'] ?? null,
                    'max_value' => $step['max_value'] ?? null,
                ]);
            }

            return $flow;
        });

        $this->audit->record('create', $flow, after: [
            'doc_type' => $flow->doc_type, 'version' => $flow->version, 'steps' => count($data['steps']),
        ], request: $request);

        return response()->json($flow->load('steps.role'), 201);
    }

    /** Nonaktifkan flow (tanpa hapus — audit) */
    public function deactivate(Request $request, int $approvalFlow): JsonResponse
    {
        $this->authorizeManage($request);

        $flow = ApprovalFlow::where('company_id', CurrentCompany::id())->findOrFail($approvalFlow);
        $flow->update(['is_active' => false]);

        $this->audit->record('update', $flow, after: ['is_active' => false], request: $request);

        return response()->json($flow->fresh());
    }

    private function authorizeManage(Request $request): void
    {
        $user = $request->user();
        $allowed = $user->hasPermission('core.approval.manage')
            || $user->roles()->where('code', 'super_admin')->exists();

        abort_unless($allowed, 403, 'Permission [core.approval.manage] diperlukan.');
    }
}
