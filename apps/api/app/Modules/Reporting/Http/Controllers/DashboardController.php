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

    /** KPI agregat dashboard — butuh salah satu dashboard.*.view (BR-110) */
    public function kpis(Request $request): JsonResponse
    {
        $allowed = collect([
            'dashboard.management.view', 'dashboard.ppic.view', 'dashboard.warehouse.view',
            'dashboard.production.view', 'dashboard.qc.view',
        ])->contains(fn ($p) => $request->user()->hasPermission($p));

        abort_unless($allowed, 403, 'Tidak ada permission dashboard.*.view.');

        return response()->json($this->service->kpis(CurrentCompany::id(), $request->user()->id));
    }
}
