<?php

namespace Modules\Reporting\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Support\CurrentCompany;
use Modules\Reporting\Services\DashboardService;

class DashboardController extends Controller
{
    public function __construct(private DashboardService $service) {}

    /** KPI agregat dashboard (BR-011 company scope; pending approval untuk user ini) */
    public function kpis(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reporting.dashboard.view'), 403);

        return response()->json($this->service->kpis(CurrentCompany::id(), $request->user()->id));
    }
}
