<?php

namespace Modules\MasterData\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class UomConversionService
{
    public function convert(int $companyId,int $materialId,float $qty,int $fromUomId,int $toUomId):float
    {
        if($qty<=0)throw new RuntimeException('Qty konversi wajib lebih besar dari nol.');if($fromUomId===$toUomId)return round($qty,4);
        $from=$this->code($companyId,$fromUomId);$to=$this->code($companyId,$toUomId);$a=$this->lengthFactor($from);$b=$this->lengthFactor($to);if($a!==null&&$b!==null)return round($qty*$a/$b,4);
        $rate=DB::table('uom_conversions')->where('company_id',$companyId)->where('material_id',$materialId)->where('from_uom_id',$fromUomId)->where('to_uom_id',$toUomId)->value('rate');if($rate!==null&&(float)$rate>0)return round($qty*(float)$rate,4);
        $inverse=DB::table('uom_conversions')->where('company_id',$companyId)->where('material_id',$materialId)->where('from_uom_id',$toUomId)->where('to_uom_id',$fromUomId)->value('rate');if($inverse!==null&&(float)$inverse>0)return round($qty/(float)$inverse,4);
        throw new RuntimeException("Konversi UOM {$from} ke {$to} belum tersedia untuk material.");
    }
    public function toMeters(int$c,int$u,float$q):float{$f=$this->lengthFactor($this->code($c,$u));if($f===null)throw new RuntimeException('UOM panjang harus meter atau yard.');return round($q*$f,4);}
    public function fromMeters(int$c,int$u,float$q):float{$f=$this->lengthFactor($this->code($c,$u));if($f===null)throw new RuntimeException('UOM panjang harus meter atau yard.');return round($q/$f,4);}
    public function code(int$c,int$u):string{$code=DB::table('uoms')->where('company_id',$c)->where('id',$u)->value('code');if($code===null)throw new RuntimeException('UOM tidak ditemukan pada company aktif.');return strtoupper(trim((string)$code));}
    private function lengthFactor(string$code):?float{if(in_array($code,['M','METER','METRE'],true)||str_starts_with($code,'MTR'))return 1.0;if(in_array($code,['YD','YARD','YARDS'],true)||str_starts_with($code,'YRD')||str_starts_with($code,'YDS'))return 0.9144;return null;}
}
