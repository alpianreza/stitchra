<?php

namespace Modules\ProductDev\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\AuditService;
use Modules\ProductDev\Models\BomVersion;
use Modules\ProductDev\Services\BomService;

class BomController extends Controller
{
    public function __construct(private BomService $service, private AuditService $audit) {}

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('pd.bom.create'), 403);

        $data = $request->validate([
            'style_id' => 'required|integer|exists:styles,id',
            'lines' => 'required|array|min:1',
            'lines.*.material_id' => 'required|integer|exists:materials,id',
            'lines.*.colorway_id' => 'nullable|integer|exists:colorways,id',
            'lines.*.qty_per_pcs' => 'required|numeric|min:0',
            'lines.*.uom_id' => 'required|integer|exists:uoms,id',
            'lines.*.wastage_pct' => 'nullable|numeric|min:0',
            'lines.*.shrinkage_pct' => 'nullable|numeric|min:0',
            'lines.*.consumption_estimated' => 'nullable|numeric|min:0',
            'lines.*.is_backflush' => 'boolean',
        ]);

        $version = $this->service->createVersion($data['style_id'], $data['lines'], $request->user());

        $this->audit->record('create', $version, after: $version->toArray(), request: $request);

        return response()->json($version, 201);
    }

    public function update(Request $request, BomVersion $bomVersion): JsonResponse
    {
        abort_unless($request->user()->hasPermission('pd.bom.update'), 403);

        $data = $request->validate([
            'lines' => 'required|array|min:1',
            'lines.*.material_id' => 'required|integer|exists:materials,id',
            'lines.*.qty_per_pcs' => 'required|numeric|min:0',
            'lines.*.uom_id' => 'required|integer|exists:uoms,id',
        ]);

        $version = $this->service->updateDraftLines($bomVersion, $data['lines']);

        return response()->json($version);
    }

    public function submit(Request $request, BomVersion $bomVersion): JsonResponse
    {
        abort_unless($request->user()->hasPermission('pd.bom.submit'), 403);

        $this->service->submit($bomVersion, $request->user());

        $this->audit->record('submit', $bomVersion, request: $request);

        return response()->json($bomVersion->fresh());
    }

    public function show(Request $request, BomVersion $bomVersion): JsonResponse
    {
        abort_unless($request->user()->hasPermission('pd.bom.view'), 403);

        return response()->json($bomVersion->load('lines.material', 'lines.uom', 'lines.colorway'));
    }
}
