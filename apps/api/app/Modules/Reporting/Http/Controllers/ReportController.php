<?php

namespace Modules\Reporting\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Support\CurrentCompany;
use Modules\Reporting\Services\ReportService;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private ReportService $service){}
    public function index(Request $request):JsonResponse{$available=array_values(array_filter($this->service->available(),fn($report)=>$request->user()->hasPermission($this->service->permissionFor($report))));abort_if($available===[],403);return response()->json(['data'=>$available]);}
    public function run(Request $request,string $report):JsonResponse{return $this->domain(function()use($request,$report){$this->authorize($request,$report);return response()->json($this->service->run(CurrentCompany::id(),$report,$this->params($request)));});}
    public function export(Request $request,string $report):StreamedResponse{$this->authorize($request,$report);$result=$this->service->run(CurrentCompany::id(),$report,$this->params($request));$csv=$this->service->toCsv($result);$safe=preg_replace('/[^a-z0-9_-]/i','_',$report);return response()->streamDownload(fn()=>print($csv),$safe.'-'.now()->format('Ymd-His').'.csv',['Content-Type'=>'text/csv; charset=UTF-8','X-Content-Type-Options'=>'nosniff']);}
    private function authorize(Request $request,string $report):void{try{$permission=$this->service->permissionFor($report);}catch(RuntimeException){abort(404,'Report tidak dikenal.');}abort_unless($request->user()->hasPermission($permission),403);}
    private function params(Request $request):array{return $request->validate(['date'=>'nullable|date_format:Y-m-d','fixed_cost_share'=>'nullable|numeric|min:0','limit'=>'nullable|integer|min:1|max:5000']);}
    private function domain(callable $callback):JsonResponse{try{return $callback();}catch(RuntimeException $e){return response()->json(['message'=>$e->getMessage()],422);}}
}
