<?php

namespace Modules\ShopFloor\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\User;
use Modules\ShopFloor\Models\ShopfloorDevice;
use RuntimeException;

class DeviceService
{
    public function enroll(int$companyId,array$data,User$user):array
    {
        $this->access($companyId,$user);
        return DB::transaction(function()use($companyId,$data,$user){
            $public=(string)Str::uuid();
            $device=ShopfloorDevice::create(['public_id'=>$public,'company_id'=>$companyId,'user_id'=>$user->id,'name'=>$data['name'],'platform'=>$data['platform']??null,'status'=>'ACTIVE']);
            $days=max(1,min(365,(int)config('shopfloor.device_token_days',30)));
            $token=$user->createToken('shopfloor-device:'.$public,['shopfloor:scan'],now()->addDays($days));
            $device->update(['token_id'=>$token->accessToken->id]);
            return['device'=>$device->fresh(),'token'=>$token->plainTextToken,'token_type'=>'Bearer','expires_at'=>$token->accessToken->expires_at?->toIso8601String()];
        });
    }
    public function list(int$companyId,User$user){$this->access($companyId,$user);return ShopfloorDevice::withoutGlobalScopes()->where('company_id',$companyId)->orderByDesc('id')->get();}
    public function revoke(int$companyId,int$deviceId,User$user):ShopfloorDevice
    {
        $this->access($companyId,$user);
        return DB::transaction(function()use($companyId,$deviceId,$user){$d=ShopfloorDevice::withoutGlobalScopes()->where('company_id',$companyId)->whereKey($deviceId)->lockForUpdate()->firstOrFail();if($d->status==='REVOKED')return$d;if($d->token_id)DB::table('personal_access_tokens')->where('id',$d->token_id)->delete();$d->update(['status'=>'REVOKED','token_id'=>null,'revoked_at'=>now(),'revoked_by'=>$user->id]);return$d->fresh();});
    }
    public function fromRequest(Request$request,int$companyId):ShopfloorDevice
    {
        $user=$request->user();$token=$user?->currentAccessToken();
        if(!$user||!$user->is_active||!$token||!$user->tokenCan('shopfloor:scan')||$user->tokenCan('api:access'))throw new RuntimeException('Token perangkat shopfloor tidak valid.');
        $device=ShopfloorDevice::withoutGlobalScopes()->where('company_id',$companyId)->where('user_id',$user->id)->where('token_id',$token->id)->where('status','ACTIVE')->first();
        if(!$device)throw new RuntimeException('Perangkat tidak aktif atau telah dicabut.');return$device;
    }
    private function access(int$companyId,User$user):void{if((int)$user->company_id!==$companyId&&!$user->companies()->whereKey($companyId)->exists())throw new RuntimeException('User tidak memiliki akses ke company perangkat.');}
}
