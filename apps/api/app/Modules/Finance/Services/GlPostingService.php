<?php

namespace Modules\Finance\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Finance\Models\AccountMapping;
use Modules\Finance\Models\Journal;
use RuntimeException;

class GlPostingService
{
    public function __construct(private JournalService $journals){}
    public function postEvent(int $companyId,string $event,string $sourceType,int $sourceId,float $amount,string $period,User $user,?string $description=null):array
    {
        if($amount<=0)throw new RuntimeException("Jurnal AUTO [{$event}] memerlukan amount > 0.");$key=hash('sha256',implode('|',[$companyId,$event,$sourceType,$sourceId]));
        return DB::transaction(function()use($companyId,$event,$sourceType,$sourceId,$amount,$period,$user,$description,$key){DB::table('companies')->where('id',$companyId)->lockForUpdate()->first();$existing=Journal::withoutGlobalScopes()->where('posting_key',$key)->first();if($existing){if(abs((float)$existing->total_debit-$amount)>0.0001)throw new RuntimeException('Idempotency conflict: source jurnal sama memiliki amount berbeda.');return['journal'=>$existing,'created'=>false];}$mapping=AccountMapping::withoutGlobalScopes()->where('company_id',$companyId)->where('event',$event)->first();if(!$mapping)throw new RuntimeException("BR-101: account mapping untuk event [{$event}] belum diisi.");if($mapping->debit_account_id===$mapping->credit_account_id)throw new RuntimeException('Debit dan credit mapping tidak boleh akun yang sama.');if(DB::table('chart_of_accounts')->where('company_id',$companyId)->whereIn('id',[$mapping->debit_account_id,$mapping->credit_account_id])->count()!==2)throw new RuntimeException('Account mapping menggunakan COA dari company lain.');$journal=$this->journals->post($companyId,['period'=>$period,'source'=>'AUTO','event'=>$event,'source_document_type'=>$sourceType,'source_document_id'=>$sourceId,'posting_key'=>$key,'description'=>$description??$event],[['coa_id'=>$mapping->debit_account_id,'debit'=>$amount],['coa_id'=>$mapping->credit_account_id,'credit'=>$amount]],$user);return['journal'=>$journal,'created'=>true];});
    }
}
