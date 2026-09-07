<?php

namespace Modules\ProductDev\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Support\CurrentCompany;
use Modules\MasterData\Models\Size;
use Modules\MasterData\Models\Style;
use Modules\ProductDev\Models\MeasurementChart;
use Modules\ProductDev\Models\StyleSpec;
use Modules\ProductDev\Models\TechPack;
use Modules\ProductDev\Services\StyleDevelopmentService;
use Modules\ProductDev\Services\TechPackService;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StyleDevelopmentController extends Controller
{
    public function __construct(private StyleDevelopmentService $service, private TechPackService $packs) {}

    /** Minimal PD lookup; no master administration or costing access is required. */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('pd.style.view')
            || $request->user()->hasPermission('pd.sample.view')
            || $request->user()->hasPermission('pd.sample.create')
            || $request->user()->hasPermission('pd.techpack.view'), 403);
        $data = $request->validate(['q' => 'nullable|string|max:128', 'per_page' => 'nullable|integer|min:1|max:100']);
        $query = Style::select('id', 'company_id', 'style_no', 'buyer_style_ref', 'description', 'lifecycle');
        if (! empty($data['q'])) {
            $term = '%'.$data['q'].'%';
            $query->where(fn ($q) => $q->where('style_no', 'like', $term)->orWhere('buyer_style_ref', 'like', $term));
        }

        return response()->json($query->orderBy('style_no')->paginate($data['per_page'] ?? 25));
    }

    public function sizes(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => 'nullable|string|max:64', 'per_page' => 'nullable|integer|min:1|max:100']);

        return response()->json(Size::where('code', 'like', '%'.($data['q'] ?? '').'%')
            ->orderBy('sort_order')->orderBy('id')->paginate($data['per_page'] ?? 50));
    }

    public function show(Request $request, Style $style): JsonResponse
    {
        $this->scope($style);
        $request->validate([
            'spec_page' => 'nullable|integer|min:1', 'chart_page' => 'nullable|integer|min:1',
            'pack_page' => 'nullable|integer|min:1',
        ]);
        $canPacks = $request->user()->hasPermission('pd.techpack.view');
        $specs = StyleSpec::where('style_id', $style->id);
        $charts = MeasurementChart::where('style_id', $style->id);
        $packs = TechPack::where('style_id', $style->id);

        return response()->json([
            'style' => $style->only('id', 'style_no', 'description', 'lifecycle'),
            'latest_spec_version' => (int) (clone $specs)->max('version'),
            'latest_chart_version' => (int) (clone $charts)->max('version'),
            'specs' => $specs->orderByDesc('version')->paginate(10, ['*'], 'spec_page'),
            'charts' => $charts->withCount('lines')->orderByDesc('version')->paginate(10, ['*'], 'chart_page'),
            'tech_packs' => $canPacks ? $packs->orderByDesc('version')->orderByDesc('id')->paginate(10, ['*'], 'pack_page') : null,
            'tech_pack_max_kb' => (int) config('pd.tech_pack_max_kb'),
            'permissions' => [
                'write_specs' => $request->user()->hasPermission('pd.style.update'),
                'view_packs' => $canPacks,
                'upload_packs' => $request->user()->hasPermission('pd.techpack.create'),
            ],
        ]);
    }

    public function sampleSources(Request $request, Style $style): JsonResponse
    {
        $this->scope($style);
        $request->validate(['kind' => 'required|in:specs,charts,tech_packs', 'per_page' => 'nullable|integer|min:1|max:100']);
        $query = match ($request->string('kind')->toString()) {
            'specs' => StyleSpec::where('style_id', $style->id)->select('id', 'version'),
            'charts' => MeasurementChart::where('style_id', $style->id)->select('id', 'version', 'unit'),
            'tech_packs' => TechPack::where('style_id', $style->id)->select('id', 'version', 'file_name'),
        };

        return response()->json($query->orderByDesc('version')->orderByDesc('id')->paginate($request->integer('per_page', 25)));
    }

    public function chart(MeasurementChart $measurementChart): JsonResponse
    {
        abort_unless(Style::whereKey($measurementChart->style_id)->exists(), 404);

        return response()->json($measurementChart->load('lines.size'));
    }

    public function storeSpec(Request $request, Style $style): JsonResponse
    {
        $this->scope($style);

        return $this->run(fn () => $this->service->createSpec($style, $request->all(), $request->user()));
    }

    public function storeChart(Request $request, Style $style): JsonResponse
    {
        $this->scope($style);

        return $this->run(fn () => $this->service->createMeasurements($style, $request->all(), $request->user()));
    }

    public function upload(Request $request, Style $style): JsonResponse
    {
        $this->scope($style);
        $request->validate(['file' => 'required|file']);

        return $this->run(fn () => $this->packs->upload($style, $request->file('file'), $request->only('upload_key', 'revision_notes'), $request->user()));
    }

    public function download(Request $request, TechPack $techPack): StreamedResponse|JsonResponse
    {
        abort_unless((int) $techPack->company_id === CurrentCompany::id(), 404);
        try {
            return $this->packs->download($techPack, $request->user());
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    private function scope(Style $style): void
    {
        abort_unless((int) $style->company_id === CurrentCompany::id(), 404);
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