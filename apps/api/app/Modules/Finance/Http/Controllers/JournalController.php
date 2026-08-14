<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\AuditService;
use Modules\Core\Support\CurrentCompany;
use Modules\Finance\Models\AccountMapping;
use Modules\Finance\Models\Journal;
use Modules\Finance\Services\JournalService;

class JournalController extends Controller
{
    public function __construct(private JournalService $service, private AuditService $audit) {}

    /** Jurnal manual — wajib balanced (BR-101) */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('finance.journal.create'), 403);

        $data = $request->validate([
            'period' => 'required|string|max:7',
            'journal_date' => 'nullable|date',
            'description' => 'nullable|string',
            'lines' => 'required|array|min:2',
            'lines.*.coa_id' => 'required|integer|exists:chart_of_accounts,id',
            'lines.*.debit' => 'nullable|numeric|min:0',
            'lines.*.credit' => 'nullable|numeric|min:0',
            'lines.*.memo' => 'nullable|string',
        ]);

        try {
            $journal = $this->service->post(CurrentCompany::id(), $data, $data['lines'], $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($journal, 201);
    }

    /** Koreksi via jurnal balik (bukan edit — BR-016) */
    public function reverse(Request $request, Journal $journal): JsonResponse
    {
        abort_unless($request->user()->hasPermission('finance.journal.reverse'), 403);

        $data = $request->validate(['reason' => 'nullable|string']);

        try {
            $reversal = $this->service->reverse($journal, $request->user(), $data['reason'] ?? null);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($reversal, 201);
    }

    public function trialBalance(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('finance.gl.view'), 403);

        $period = $request->query('period', now()->format('Y-m'));

        return response()->json(['period' => $period, 'data' => $this->service->trialBalance(CurrentCompany::id(), $period)]);
    }

    /** BR-103: tutup periode */
    public function closePeriod(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('finance.period.close'), 403);

        $data = $request->validate(['period' => 'required|string|max:7']);

        $period = $this->service->closePeriod(CurrentCompany::id(), $data['period'], $request->user());

        return response()->json($period);
    }

    /** Mapping event → akun (BR-101 jurnal AUTO) */
    public function setMapping(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('finance.mapping.update'), 403);

        $data = $request->validate([
            'event' => 'required|string|in:'.implode(',', AccountMapping::EVENTS),
            'debit_account_id' => 'required|integer|exists:chart_of_accounts,id',
            'credit_account_id' => 'required|integer|exists:chart_of_accounts,id',
        ]);

        $mapping = AccountMapping::updateOrCreate(
            ['company_id' => CurrentCompany::id(), 'event' => $data['event']],
            ['debit_account_id' => $data['debit_account_id'], 'credit_account_id' => $data['credit_account_id'], 'updated_by' => $request->user()->id],
        );

        $this->audit->record('update', 'account_mappings', documentId: $mapping->id, after: $mapping->toArray(), request: $request);

        return response()->json($mapping);
    }
}
