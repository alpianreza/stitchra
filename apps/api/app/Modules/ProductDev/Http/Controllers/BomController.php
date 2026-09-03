<?php

namespace Modules\ProductDev\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Core\Services\AuditService;
use Modules\Core\Support\CurrentCompany;
use Modules\ProductDev\Models\BomVersion;
use Modules\ProductDev\Services\BomService;
use Modules\Production\Services\NamedProductionMeasureService;
use RuntimeException;

class BomController extends Controller
{
    public function __construct(private BomService $service, private AuditService $audit) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(includeStyle: true));
        try { $version = $this->service->createVersion($data['style_id'], $data['lines'], $request->user()); }
        catch (RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
        $this->audit->record('create', $version, after: $version->toArray(), request: $request);
        return response()->json($version, 201);
    }

    public function update(Request $request, BomVersion $bomVersion): JsonResponse
    {
        $this->assertTenant($bomVersion); $data = $request->validate($this->rules());
        try { $version = $this->service->updateDraftLines($bomVersion, $data['lines']); }
        catch (RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
        return response()->json($version);
    }

    public function submit(Request $request, BomVersion $bomVersion): JsonResponse
    {
        $this->assertTenant($bomVersion);
        try { $this->service->submit($bomVersion, $request->user()); }
        catch (RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
        $this->audit->record('submit', $bomVersion, request: $request);
        return response()->json($bomVersion->fresh());
    }

    public function show(Request $request, BomVersion $bomVersion): JsonResponse
    {
        $this->assertTenant($bomVersion);
        return response()->json($bomVersion->load('lines.material', 'lines.uom', 'lines.colorway'));
    }

    private function rules(bool $includeStyle = false): array
    {
        $companyId = CurrentCompany::id(); $tenantExists = fn (string $table) => Rule::exists($table, 'id')->where('company_id', $companyId);
        $rules = [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.material_id' => ['required', 'integer', $tenantExists('materials')],
            'lines.*.colorway_id' => ['nullable', 'integer', $tenantExists('colorways')],
            'lines.*.qty_per_pcs' => ['required', 'numeric', 'gt:0'],
            'lines.*.uom_id' => ['required', 'integer', $tenantExists('uoms')],
            'lines.*.wastage_pct' => ['nullable', 'numeric', 'between:0,100'],
            'lines.*.shrinkage_pct' => ['nullable', 'numeric', 'between:0,100'],
            'lines.*.consumption_estimated' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.is_backflush' => ['boolean'],
            'lines.*.backflush_stage' => ['nullable', 'string', Rule::in(NamedProductionMeasureService::BACKFLUSH_STAGES)],
        ];
        if ($includeStyle) $rules = ['style_id' => ['required', 'integer', $tenantExists('styles')]] + $rules;
        return $rules;
    }

    private function assertTenant(BomVersion $version): void
    {
        abort_unless((int) $version->bom->style->company_id === CurrentCompany::id(), 404);
    }
}
