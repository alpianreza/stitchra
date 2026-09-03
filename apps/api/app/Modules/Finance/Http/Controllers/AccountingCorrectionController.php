<?php
namespace Modules\Finance\Http\Controllers;
use Illuminate\Http\JsonResponse;use Illuminate\Http\Request;use Illuminate\Routing\Controller;use Modules\Finance\Models\AccountingCorrection;use Modules\Finance\Models\Journal;use Modules\Finance\Services\AccountingCorrectionService;use RuntimeException;
class AccountingCorrectionController extends Controller
{
 public function __construct(private AccountingCorrectionService$service){}
 public function request(Request$r,Journal$journal):JsonResponse{$d=$r->validate(['corrected_amount'=>'required|numeric|min:0','reason'=>'required|string|max:2000','correction_version'=>'required|integer|min:1']);return$this->domain(fn()=>response()->json($this->service->request($journal,(float)$d['corrected_amount'],$d['reason'],(int)$d['correction_version'],$r->user()),201));}
 public function approve(Request$r,AccountingCorrection$accountingCorrection):JsonResponse{$d=$r->validate(['note'=>'nullable|string|max:2000']);return$this->domain(fn()=>response()->json($this->service->approve($accountingCorrection,$r->user(),$d['note']??null)));}
 public function reject(Request$r,AccountingCorrection$accountingCorrection):JsonResponse{$d=$r->validate(['note'=>'nullable|string|max:2000']);return$this->domain(fn()=>response()->json($this->service->reject($accountingCorrection,$r->user(),$d['note']??null)));}
 public function post(Request$r,AccountingCorrection$accountingCorrection):JsonResponse{return$this->domain(fn()=>response()->json($this->service->post($accountingCorrection,$r->user())));}
 public function journal(Request$r,Journal$journal):JsonResponse{return$this->domain(fn()=>response()->json($this->service->byJournal($journal,$r->user())));}
 public function show(Request$r,AccountingCorrection$accountingCorrection):JsonResponse{return$this->domain(fn()=>response()->json($this->service->detail($accountingCorrection,$r->user())));}
 public function lineage(Request$r,AccountingCorrection$accountingCorrection):JsonResponse{return$this->domain(fn()=>response()->json($this->service->lineage($accountingCorrection,$r->user())));}
 private function domain(callable$cb):JsonResponse{try{return$cb();}catch(RuntimeException$e){return response()->json(['message'=>$e->getMessage()],422);}}
}
