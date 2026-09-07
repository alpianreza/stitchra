<?php

namespace Modules\ProductDev\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Core\Support\CurrentCompany;
use Modules\MasterData\Models\Style;
use Modules\ProductDev\Models\Sample;
use Modules\ProductDev\Services\SampleService;
use Modules\Production\Models\ProductionOrder;
use RuntimeException;

class SampleController extends Controller
{
    public function __construct(private SampleService $service) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => 'nullable|string|max:128', 'style_id' => 'nullable|integer|min:1',
            'stage' => ['nullable', Rule::in(Sample::STAGES)],
            'buyer_status' => ['nullable', Rule::in(Sample::BUYER_STATUSES)],
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $query = Sample::with('style:id,style_no');
        foreach (['style_id', 'stage', 'buyer_status'] as $filter) {
            if (! empty($data[$filter])) {
                $query->where($filter, $data[$filter]);
            }
        }
        if (! empty($data['q'])) {
            $query->where('doc_no', 'like', '%'.$data['q'].'%');
        }

        $page = $query->orderByDesc('id')->paginate($data['per_page'] ?? 25);

        return response()->json([...$page->toArray(), 'permissions' => ['create' => $request->user()->hasPermission('pd.sample.create')]]);
    }

    public function show(Request $request, Sample $sample): JsonResponse
    {
        $this->scope($sample);
        $request->validate(['approval_page' => 'nullable|integer|min:1', 'use_page' => 'nullable|integer|min:1']);
        $sample->load('style:id,style_no', 'styleSpec', 'measurementChart');
        $sample->setAttribute('superseded', $sample->isSuperseded());
        $sample->setAttribute('approvals', $sample->approvals()->orderByDesc('id')->paginate(20, ['*'], 'approval_page'));
        $sample->setAttribute('production_uses', ProductionOrder::where('production_sample_id', $sample->id)
            ->select('id', 'doc_no', 'status', 'sample_gate_checked_at')->orderByDesc('id')->paginate(20, ['*'], 'use_page'));
        $sample->setAttribute('permissions', [
            'create' => $request->user()->hasPermission('pd.sample.create'),
            'respond' => $request->user()->hasPermission('pd.sample.submit'),
        ]);

        return response()->json($sample);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['style_id' => 'required|integer|min:1']);
        $style = Style::whereKey($data['style_id'])->firstOrFail();

        return $this->run(fn () => $this->service->create($style, $request->all(), $request->user()));
    }

    public function addApproval(Request $request, Sample $sample): JsonResponse
    {
        $this->scope($sample);

        return $this->run(fn () => $this->service->recordResponse($sample, $request->all(), $request->user()));
    }

    private function scope(Sample $sample): void
    {
        abort_unless((int) $sample->company_id === CurrentCompany::id(), 404);
    }

    private function run(callable $callback): JsonResponse
    {
        try {
            return response()->json($callback(), 201);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
