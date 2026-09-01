<?php

namespace Modules\ShopFloor\Http\Controllers;

use Illuminate\Http\JsonResponse;use Illuminate\Http\Request;use Illuminate\Routing\Controller;use Illuminate\Validation\Rule;use Modules\Core\Support\CurrentCompany;use Modules\ShopFloor\Exceptions\ScanConflictException;use Modules\ShopFloor\Services\DeviceService;use Modules\ShopFloor\Services\ScanService;use RuntimeException;
class OfflineScanController extends Controller
{
 public function __construct(private DeviceService$devices,private ScanService$scans){}
 public function sync(Request$r):JsonResponse
 {
  $c=CurrentCompany::id();try{$device=$this->devices->fromRequest($r,$c);}catch(RuntimeException$e){return response()->json(['message'=>$e->getMessage()],403);}$max=max(1,min(500,(int)config('shopfloor.sync_batch_size',100)));
  $d=$r->validate(['events'=>['required','array','min:1','max:'.$max],'events.*.client_event_id'=>'required|string|max:64|distinct','events.*.expected_bundle_version'=>'required|integer|min:0','events.*.client_scanned_at'=>'required|date','events.*.bundle_no'=>'required|string|max:40','events.*.operation_id'=>['required','integer',Rule::exists('operations','id')->where('company_id',$c)],'events.*.direction'=>'required|in:IN,OUT','events.*.stage'=>'required|in:SEWING,FINISHING','events.*.line_id'=>['nullable','integer',Rule::exists('lines','id')->where('company_id',$c)],'events.*.employee_id'=>['nullable','integer',Rule::exists('employees','id')->where('company_id',$c)]]);
  $out=[];$counts=['applied'=>0,'replayed'=>0,'conflict'=>0,'rejected'=>0];foreach($d['events']as$e){try{$x=$this->scans->syncScan($c,$e,$r->user(),$device);$status=$x['status'];$out[]=['client_event_id'=>$e['client_event_id'],'status'=>$status,'scan_id'=>$x['scan']->id,'bundle_version'=>(int)$x['scan']->bundle_version];$counts[$status]++;}catch(ScanConflictException$x){$out[]=['client_event_id'=>$e['client_event_id'],'status'=>'conflict','message'=>$x->getMessage(),'current_bundle_version'=>$x->currentVersion,'snapshot'=>$x->snapshot];$counts['conflict']++;}catch(RuntimeException$x){$out[]=['client_event_id'=>$e['client_event_id'],'status'=>'rejected','message'=>$x->getMessage()];$counts['rejected']++;}}
  return response()->json(['device_id'=>$device->public_id,'summary'=>$counts,'results'=>$out],207);
 }
}
