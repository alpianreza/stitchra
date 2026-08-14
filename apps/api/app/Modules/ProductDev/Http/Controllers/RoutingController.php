<?php

namespace Modules\ProductDev\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\AuditService;
use Modules\ProductDev\Models\RoutingVersion;
use Modules\ProductDev\Services\RoutingService;

class RoutingController extends Controller
{
    public function __construct(private RoutingService $service, private AuditService $audit) {}

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('pd.routing.create'), 403);

        $data = $request->validate([
            'style_id' => 'required|integer|exists:styles,id',
            'operations' => 'required|array|min:1',
            'operations.*.operation_id' => 'required|integer|exists:operations,id',
            'operations.*.smv' => 'required|numeric|min:0',
            'operations.*.seq' => 'nullable|integer|min:1',
            'operations.*.machine_type' => 'nullable|string|max:64',
        ]);

        $version = $this->service->createVersion($data['style_id'], $data['operations'], $request->user());

        $this->audit->record('create', $version, after: $version->toArray(), request: $request);

        return response()->json($version, 201);
    }

    public function submit(Request $request, RoutingVersion $routingVersion): JsonResponse
    {
        abort_unless($request->user()->hasPermission('pd.routing.submit'), 403);

        $this->service->submit($routingVersion, $request->user());

        $this->audit->record('submit', $routingVersion, request: $request);

        return response()->json($routingVersion->fresh());
    }

    public function show(Request $request, RoutingVersion $routingVersion): JsonResponse
    {
        abort_unless($request->user()->hasPermission('pd.routing.view'), 403);

        return response()->json($routingVersion->load('operations.operation'));
    }
}
