<?php

namespace Modules\ShopFloor\Services;

use Illuminate\Http\Request;use Illuminate\Support\Facades\DB;use Illuminate\Support\Str;use Modules\Core\Models\User;use Modules\Core\Services\AuditService;use Modules\ShopFloor\Models\ShopfloorDevice;use RuntimeException;
class DeviceService
{
 public function __construct(private AuditService$audit){}
 public function enroll(int$c,array$d,User$u):array{$this->access($c,$u);return DB::transaction(function()use($c,$d,$u){$public=(string)Str::uuid();$device=ShopfloorDevice::create(['public_id'=>$public,'company_id'=>$c,'user_id'=>$u->id,'name'=>$d['name'],'platform'=>$d['platform']??null,'status'=>'ACTIVE']);$days=max(1,min(365,(int)config('shopfloor.device_token_days',30)));$token=$u->createToken('shopfloor-device:'.$public,['shopfloor:scan'],now()->addDays($days));$device->update(['token_id'=>$token->accessToken->id]);$this->audit->record('create',$device,after:['public_id'=>$public,'name'=>$device->name,'expires_at'=>$token->accessToken->expires_at?->toIso8601String()]);return['device'=>$device->fresh(),'token'=>$token->plainTextToken,'token_type'=>'Bearer','expires_at'=>$token->accessToken->expires_at?->toIso8601String()];});}
 public function list(int$c,User$u){$this->access($c,$u);return ShopfloorDevice::withoutGlobalScopes()->where('company_id',$c)->orderByDesc('id')->get();}
 public function revoke(int$c,int$id,User$u):ShopfloorDevice{$this->access($c,$u);return DB::transaction(function()use($c,$id,$u){$d=ShopfloorDevice::withoutGlobalScopes()->where('company_id',$c)->whereKey($id)->lockForUpdate()->firstOrFail();if($d->status==='REVOKED')return$d;$before=['status'=>$d->status,'public_id'=>$d->public_id];if($d->token_id)DB::table('personal_access_tokens')->where('id',$d->token_id)->delete();$d->update(['status'=>'REVOKED','token_id'=>null,'revoked_at'=>now(),'revoked_by'=>$u->id]);$this->audit->record('update',$d,before:$before,after:['status'=>'REVOKED','public_id'=>$d->public_id]);return$d->fresh();});}
 public function fromRequest(Request$r,int$c):ShopfloorDevice{$u=$r->user();$token=$u?->currentAccessToken();if(!$u||!$u->is_active||!$token||!$u->tokenCan('shopfloor:scan')||$u->tokenCan('api:access'))throw new RuntimeException('Token perangkat shopfloor tidak valid.');$d=ShopfloorDevice::withoutGlobalScopes()->where('company_id',$c)->where('user_id',$u->id)->where('token_id',$token->id)->where('status','ACTIVE')->first();if(!$d)throw new RuntimeException('Perangkat tidak aktif atau telah dicabut.');return$d;}
 private function access(int$c,User$u):void{if((int)$u->company_id!==$c&&!$u->companies()->whereKey($c)->exists())throw new RuntimeException('User tidak memiliki akses ke company perangkat.');}
}
