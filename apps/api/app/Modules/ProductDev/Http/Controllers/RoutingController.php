<?php

namespace Modules\ProductDev\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Core\Services\AuditService;
use Modules\Core\Support\CurrentCompany;
use Modules\ProductDev\Models\RoutingVersion;
use Modules\ProductDev\Services\RoutingService;
use RuntimeException;

class RoutingController extends Controller
{
    public function __construct(private RoutingService $service, private AuditService $audit) {}

    public function store(Request $request): JsonResponse
    {
        $companyId = CurrentCompany::id();
        $tenantExists = fn (string $table) => Rule::exists($table, 'id')->where('company_id', $companyId);

        $data = $request->validate([
            'style_id' => ['required', 'integer', $tenantExists('styles')],
            'operations' => ['required', 'array', 'min:1'],
            'operations.*.operation_id' => ['required', 'integer', $tenantExists('operations')],
            'operations.*.smv' => ['required', 'numeric', 'gt:0'],
            'operations.*.seq' => ['nullable', 'integer', 'min:1', 'distinct'],
            'operations.*.machine_type' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $version = $this->service->createVersion($data['style_id'], $data['operations'], $request->user());
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $this->audit->record('create', $version, after: $version->toArray(), request: $request);

        return response()->json($version, 201);
    }

    public function submit(Request $request, RoutingVersion $routingVersion): JsonResponse
    {
        $this->assertTenant($routingVersion);

        try {
            $this->service->submit($routingVersion, $request->user());
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $this->audit->record('submit', $routingVersion, request: $request);

        return response()->json($routingVersion->fresh());
    }

    public function show(Request $request, RoutingVersion $routingVersion): JsonResponse
    {
        $this->assertTenant($routingVersion);

        return response()->json($routingVersion->load('operations.operation'));
    }

    private function assertTenant(RoutingVersion $version): void
    {
        abort_unless((int) $version->routing->style->company_id === CurrentCompany::id(), 404);
    }
}
