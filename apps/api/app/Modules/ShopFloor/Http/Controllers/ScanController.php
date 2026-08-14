<?php

namespace Modules\ShopFloor\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Support\CurrentCompany;
use Modules\ShopFloor\Services\ScanService;

class ScanController extends Controller
{
    public function __construct(private ScanService $service) {}

    /** BR-062: scan bundle (keyboard-wedge scanner / manual) — IN/OUT per operasi */
    public function scan(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('shopfloor.scan.create'), 403);

        $data = $request->validate([
            'bundle_no' => 'required|string|max:40',
            'operation_id' => 'required|integer|exists:operations,id',
            'direction' => 'required|in:IN,OUT',
            'stage' => 'required|in:SEWING,FINISHING',
            'line_id' => 'nullable|integer|exists:lines,id',
            'employee_id' => 'nullable|integer|exists:employees,id',
        ]);

        try {
            $scan = $this->service->scan(CurrentCompany::id(), $data['bundle_no'], $data, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($scan, 201);
    }

    /** BR-063: WIP per MO per stage */
    public function wip(Request $request, int $productionOrder): JsonResponse
    {
        abort_unless($request->user()->hasPermission('shopfloor.scan.view'), 403);

        return response()->json(['data' => $this->service->wipByStage($productionOrder)]);
    }

    /** Daily output per line (agregasi scan OUT) */
    public function dailyOutput(Request $request, int $line): JsonResponse
    {
        abort_unless($request->user()->hasPermission('shopfloor.scan.view'), 403);

        $date = $request->query('date', now()->toDateString());

        return response()->json(['data' => $this->service->dailyOutput($line, $date)]);
    }
}
