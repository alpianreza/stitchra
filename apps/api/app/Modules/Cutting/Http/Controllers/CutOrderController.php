<?php

namespace Modules\Cutting\Http\Controllers;
use Illuminate\Http\JsonResponse;use Illuminate\Http\Request;use Illuminate\Routing\Controller;use Illuminate\Validation\Rule;use Modules\Core\Support\CurrentCompany;use Modules\Cutting\Models\CutOrder;use Modules\Cutting\Services\CuttingService;use Modules\Production\Models\ProductionOrder;use RuntimeException;
class CutOrderController extends Controller
{
 public function __construct(private CuttingService $service){}
 public function store(Request$r,ProductionOrder$mo):JsonResponse{$c=CurrentCompany::id();$d=$r->validate(['lines'=>'required|array|min:1','lines.*.colorway_id'=>['required','integer',Rule::exists('colorways','id')->whereIn('style_id',[$mo->style_id])],'lines.*.size_id'=>['required','integer',Rule::exists('sizes','id')->where('company_id',$c)],'lines.*.qty_cut'=>'required|numeric|min:0.0001']);return$this->domain(fn()=>response()->json($this->service->create($mo,$d['lines'],$r->user()),201));}
 public function recordMarker(Request$r,CutOrder$co):JsonResponse{$c=CurrentCompany::id();$d=$r->validate(['markers'=>'required|array|min:1','markers.*.roll_id'=>['required','integer',Rule::exists('fabric_rolls','id')->where('company_id',$c)],'markers.*.uom_id'=>['nullable','integer',Rule::exists('uoms','id')->where('company_id',$c)],'markers.*.marker_length'=>'required_without:markers.*.marker_length_m|nullable|numeric|gt:0','markers.*.marker_length_m'=>'required_without:markers.*.marker_length|nullable|numeric|gt:0','markers.*.qty_fabric_used'=>'required_without:markers.*.qty_fabric_used_m|nullable|numeric|gt:0','markers.*.qty_fabric_used_m'=>'required_without:markers.*.qty_fabric_used|nullable|numeric|gt:0','markers.*.plies'=>'required|integer|min:1','markers.*.efficiency_pct'=>'nullable|numeric|min:0|max:100']);return$this->domain(fn()=>response()->json($this->service->recordMarker($co,$d['markers'],$r->user())));}
 public function generateBundles(Request$r,CutOrder$co,int$line):JsonResponse{$d=$r->validate(['bundle_size'=>'required|integer|min:1']);return$this->domain(fn()=>response()->json(['data'=>$b=$this->service->generateBundles($co,$line,(int)$d['bundle_size'],$r->user()),'count'=>count($b)],201));}
 public function complete(Request$r,CutOrder$co):JsonResponse{return$this->domain(fn()=>response()->json($this->service->complete($co,$r->user())));}
 private function domain(callable$f):JsonResponse{try{return$f();}catch(RuntimeException$e){return response()->json(['message'=>$e->getMessage()],422);}}
}
