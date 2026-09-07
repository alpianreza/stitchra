<?php

namespace Modules\Purchasing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Core\Support\CurrentCompany;
use Modules\Purchasing\Models\Rfq;
use Modules\Purchasing\Services\SourcingService;
use RuntimeException;

class SourcingController extends Controller
{
    public function __construct(private SourcingService $service) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(Rfq::STATUSES)], 'per_page' => 'nullable|integer|min:1|max:100',
            'q' => 'nullable|string|max:128',
        ]);
        $query = Rfq::withCount('lines', 'quotations')->with('purchaseOrder:id,rfq_id,doc_no,status');
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['q'])) {
            $query->where('doc_no', 'like', '%'.$data['q'].'%');
        }

        return response()->json($query->orderByDesc('id')->paginate($data['per_page'] ?? 25));
    }

    public function show(Request $request, Rfq $rfq): JsonResponse
    {
        return $this->run(fn () => $this->service->comparison($rfq, $request->user()));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'deadline' => 'nullable|date', 'notes' => 'nullable|string|max:5000',
            'lines' => 'required|array|min:1|max:100', 'supplier_ids' => 'required|array|min:1|max:100',
        ]);

        return $this->run(fn () => $this->service->createRfq(
            CurrentCompany::id(), $data, $data['lines'], $data['supplier_ids'], $request->user(),
        ), 201);
    }

    public function quotation(Request $request, Rfq $rfq): JsonResponse
    {
        return $this->run(fn () => $this->service->addQuotation($rfq, $request->all(), $request->user()), 201);
    }

    public function award(Request $request, Rfq $rfq, int $quotationId): JsonResponse
    {
        return $this->run(fn () => $this->service->award(
            $rfq, $quotationId, $request->only('order_date', 'expected_date', 'reason'), $request->user(),
        ));
    }

    public function close(Request $request, Rfq $rfq): JsonResponse
    {
        return $this->run(fn () => $this->service->close($rfq, $request->user()));
    }

    /** Sourcing-specific, tenant-scoped lookup: no need to grant master-data administration. */
    public function options(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['materials', 'uoms', 'suppliers', 'currencies', 'pr_lines'])],
            'q' => 'nullable|string|max:128', 'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $companyId = CurrentCompany::id();
        $term = '%'.($data['q'] ?? '').'%';
        if ($data['kind'] === 'pr_lines') {
            $query = DB::table('pr_lines as l')->join('purchase_requests as p', 'p.id', '=', 'l.purchase_request_id')
                ->join('materials as m', 'm.id', '=', 'l.material_id')
                ->where('p.company_id', $companyId)->where('m.company_id', $companyId)->where('p.status', 'APPROVED')
                ->where(fn ($q) => $q->where('p.doc_no', 'like', $term)->orWhere('m.name', 'like', $term)->orWhere('m.code', 'like', $term))
                ->select('l.id', 'l.material_id', 'l.uom_id', 'l.qty', 'p.doc_no', 'm.code', 'm.name')->orderByDesc('l.id');

            return response()->json($query->paginate($data['per_page'] ?? 50)->through(fn ($row) => [
                'id' => $row->id, 'label' => "{$row->doc_no} / {$row->code} — {$row->name}",
                'material_id' => $row->material_id, 'uom_id' => $row->uom_id, 'qty' => $row->qty,
            ]));
        }
        $query = DB::table($data['kind'])->where('company_id', $companyId)
            ->where(fn ($q) => $q->where('code', 'like', $term)->orWhere('name', 'like', $term))->orderBy('code');
        if (in_array($data['kind'], ['materials', 'suppliers'], true)) {
            $query->where('is_active', true)->whereNull('deleted_at');
        }

        return response()->json($query->select('id', 'code', 'name')->paginate($data['per_page'] ?? 50)
            ->through(fn ($row) => ['id' => $row->id, 'label' => "{$row->code} — {$row->name}"]));
    }

    private function run(callable $callback, int $status = 200): JsonResponse
    {
        try {
            return response()->json($callback(), $status);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
