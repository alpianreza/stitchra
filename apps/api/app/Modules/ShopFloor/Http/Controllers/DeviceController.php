<?php

namespace Modules\ShopFloor\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Support\CurrentCompany;
use Modules\ShopFloor\Services\DeviceService;
use RuntimeException;

class DeviceController extends Controller
{
    public function __construct(private DeviceService$service){}
    public function index(Request$r):JsonResponse{return$this->domain(fn()=>response()->json(['data'=>$this->service->list(CurrentCompany::id(),$r->user())]));}
    public function store(Request$r):JsonResponse{$d=$r->validate(['name'=>'required|string|max:100','platform'=>'nullable|string|max:50']);return$this->domain(fn()=>response()->json($this->service->enroll(CurrentCompany::id(),$d,$r->user()),201));}
    public function destroy(Request$r,int$device):JsonResponse{return$this->domain(fn()=>response()->json(['data'=>$this->service->revoke(CurrentCompany::id(),$device,$r->user()),'message'=>'Perangkat dicabut.']));}
    private function domain(callable$f):JsonResponse{try{return$f();}catch(RuntimeException$e){return response()->json(['message'=>$e->getMessage()],422);}}
}
