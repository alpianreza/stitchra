<?php

namespace Modules\Finance\Http\Controllers;
use Illuminate\Http\JsonResponse;use Illuminate\Http\Request;use Illuminate\Routing\Controller;use Modules\Core\Support\CurrentCompany;use Modules\Finance\Models\FxRevaluationRun;use Modules\Finance\Services\FxRevaluationService;use RuntimeException;
class FxRevaluationController extends Controller{public function __construct(private FxRevaluationService$service){}public function run(Request$r):JsonResponse{$d=$r->validate(['period'=>['required','regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);return$this->domain(fn()=>response()->json($this->service->run(CurrentCompany::id(),$d['period'],$r->user()),201));}public function reverse(Request$r,FxRevaluationRun$fxRevaluationRun):JsonResponse{return$this->domain(fn()=>response()->json($this->service->reverse($fxRevaluationRun,$r->user())));}private function domain(callable$c):JsonResponse{try{return$c();}catch(RuntimeException$e){return response()->json(['message'=>$e->getMessage()],422);}}}
