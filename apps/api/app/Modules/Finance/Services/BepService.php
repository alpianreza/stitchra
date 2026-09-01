<?php

namespace Modules\Finance\Services;

use Modules\ProductDev\Models\CostSheet;
use RuntimeException;

class BepService
{
    public function compute(float $fixed,float $price,float $variable):array{$contribution=$price-$variable;if($fixed<0)throw new RuntimeException('Fixed cost tidak boleh negatif.');if($price<=0||$variable<0||$contribution<=0)throw new RuntimeException("BR-104: harga ({$price}) harus > variable cost ({$variable}).");$qty=(int)ceil($fixed/$contribution);return['bep_qty'=>$qty,'bep_revenue'=>round($qty*$price,4),'contribution_margin_per_unit'=>round($contribution,4),'contribution_margin_ratio'=>round($contribution/$price,6)];}
    public function forStyle(int $companyId,int $styleId,float $fixed):array{$sheet=CostSheet::withoutGlobalScopes()->where('company_id',$companyId)->where('style_id',$styleId)->where('status','APPROVED')->latest('id')->first();if(!$sheet)throw new RuntimeException("Style #{$styleId} belum punya cost sheet APPROVED.");if((float)$sheet->fob_price<=0)throw new RuntimeException("Cost sheet {$sheet->doc_no} belum punya FOB price.");return $this->compute($fixed,(float)$sheet->fob_price,$sheet->totalManufacturingCost())+['style_id'=>$styleId,'cost_sheet'=>$sheet->doc_no];}
    public function factoryWide(int $companyId,string $period,float $fixed):array
    {
        if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$period))throw new RuntimeException('Period BEP wajib format YYYY-MM.');
        $latest=CostSheet::withoutGlobalScopes()->selectRaw('MAX(id)')->where('company_id',$companyId)->where('status','APPROVED')->groupBy('style_id');
        $sheets=CostSheet::withoutGlobalScopes()->where('company_id',$companyId)->whereIn('id',$latest)->where('fob_price','>',0)->get();if($sheets->isEmpty())throw new RuntimeException('Belum ada cost sheet APPROVED dengan FOB.');$price=$sheets->avg(fn($s)=>(float)$s->fob_price);$variable=$sheets->avg(fn($s)=>$s->totalManufacturingCost());return $this->compute($fixed,(float)$price,(float)$variable)+['period'=>$period,'styles_count'=>$sheets->count()];
    }
}
