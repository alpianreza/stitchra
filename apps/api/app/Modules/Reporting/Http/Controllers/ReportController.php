<?php

namespace Modules\Reporting\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Support\CurrentCompany;
use Modules\Reporting\Services\ReportService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private ReportService $service) {}

    /** Daftar report yang tersedia */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reporting.report.view'), 403);

        return response()->json(['data' => $this->service->available()]);
    }

    /** Jalankan report — params via query (date, fixed_cost_share, ...) */
    public function run(Request $request, string $report): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reporting.report.view'), 403);

        try {
            $result = $this->service->run(CurrentCompany::id(), $report, $request->query());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json($result);
    }

    /** Export CSV (download) */
    public function export(Request $request, string $report): StreamedResponse
    {
        abort_unless($request->user()->hasPermission('reporting.report.export'), 403);

        $result = $this->service->run(CurrentCompany::id(), $report, $request->query());
        $csv = $this->service->toCsv($result);

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, "{$report}-".now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }
}
