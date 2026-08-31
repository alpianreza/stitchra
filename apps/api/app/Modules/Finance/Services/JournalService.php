<?php

namespace Modules\Finance\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Finance\Models\GlPeriod;
use Modules\Finance\Models\Journal;
use RuntimeException;

class JournalService
{
    private const TOLERANCE=0.0001;
    public function __construct(private NumberingService $numbering,private AuditService $audit){}
    public function post(int $companyId,array $meta,array $lines,User $user):Journal
    {
        $this->assertAccess($user,$companyId);$period=(string)($meta['period']??'');if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$period))throw new RuntimeException('Period wajib format YYYY-MM.');if(count($lines)<2)throw new RuntimeException('Jurnal wajib minimal 2 baris.');
        $date=Carbon::parse($meta['journal_date']??now()->toDateString());if($date->format('Y-m')!==$period)throw new RuntimeException('Journal date harus berada pada period jurnal.');
        $source=$meta['source']??'MANUAL';if(!in_array($source,Journal::SOURCES,true))throw new RuntimeException('Source jurnal tidak valid.');$debit=0.0;$credit=0.0;$coaIds=[];
        foreach($lines as $i=>$line){$d=(float)($line['debit']??0);$c=(float)($line['credit']??0);if(($d>0&&$c>0)||($d<=0&&$c<=0))throw new RuntimeException("Baris {$i}: isi tepat satu sisi debit XOR credit.");$debit+=$d;$credit+=$c;$coaIds[]=(int)($line['coa_id']??0);}
        if(abs($debit-$credit)>self::TOLERANCE)throw new RuntimeException("BR-101: jurnal tidak balance — debit {$debit} ≠ kredit {$credit}.");
        if(DB::table('chart_of_accounts')->where('company_id',$companyId)->whereIn('id',array_unique($coaIds))->count()!==count(array_unique($coaIds)))throw new RuntimeException('Seluruh COA jurnal wajib berasal dari company yang sama.');
        return DB::transaction(function()use($companyId,$meta,$lines,$debit,$credit,$user,$period,$date,$source){DB::table('companies')->where('id',$companyId)->lockForUpdate()->first();$gl=$this->openPeriod($companyId,$period);$journal=Journal::create(['company_id'=>$companyId,'doc_no'=>$this->numbering->next($companyId,'JE'),'period'=>$gl->period,'journal_date'=>$date->toDateString(),'source'=>$source,'event'=>$meta['event']??null,'source_document_type'=>$meta['source_document_type']??null,'source_document_id'=>$meta['source_document_id']??null,'posting_key'=>$meta['posting_key']??null,'description'=>$meta['description']??null,'total_debit'=>round($debit,4),'total_credit'=>round($credit,4),'status'=>'POSTED','created_by'=>$user->id]);foreach($lines as $line)$journal->lines()->create(['coa_id'=>$line['coa_id'],'debit'=>(float)($line['debit']??0),'credit'=>(float)($line['credit']??0),'memo'=>$line['memo']??null]);$this->audit->record('create',$journal,after:['doc_no'=>$journal->doc_no,'total'=>$journal->total_debit,'event'=>$journal->event]);return $journal->load('lines');});
    }
    public function reverse(Journal $journal,User $user,?string $reason=null):Journal
    {
        return DB::transaction(function()use($journal,$user,$reason){$locked=Journal::withoutGlobalScopes()->with('lines')->whereKey($journal->id)->lockForUpdate()->firstOrFail();$this->assertAccess($user,(int)$locked->company_id);if($locked->status==='VOID'||Journal::withoutGlobalScopes()->where('reverses_journal_id',$locked->id)->exists())throw new RuntimeException('Jurnal sudah direverse.');$lines=$locked->lines->map(fn($line)=>['coa_id'=>$line->coa_id,'debit'=>(float)$line->credit,'credit'=>(float)$line->debit,'memo'=>'Reversal '.$locked->doc_no])->all();$reversal=$this->post($locked->company_id,['period'=>$locked->period,'journal_date'=>now()->format('Y-m')===$locked->period?now()->toDateString():Carbon::createFromFormat('Y-m-d',$locked->period.'-01')->endOfMonth()->toDateString(),'source'=>$locked->source,'event'=>'REVERSAL','source_document_type'=>'journals','source_document_id'=>$locked->id,'description'=>'REVERSAL '.$locked->doc_no.($reason?': '.$reason:'')],$lines,$user);$reversal->update(['reverses_journal_id'=>$locked->id]);$locked->update(['status'=>'VOID']);$this->audit->record('reverse',$locked,after:['reversal'=>$reversal->doc_no]);return $reversal;});
    }
    private function openPeriod(int $companyId,string $period):GlPeriod{$gl=GlPeriod::withoutGlobalScopes()->where('company_id',$companyId)->where('period',$period)->lockForUpdate()->first();if($gl===null)$gl=GlPeriod::create(['company_id'=>$companyId,'period'=>$period,'status'=>'OPEN']);if($gl->status==='CLOSED')throw new RuntimeException("BR-103: periode {$period} sudah CLOSED.");return $gl;}
    public function closePeriod(int $companyId,string $period,User $user):GlPeriod{$this->assertAccess($user,$companyId);if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$period))throw new RuntimeException('Period wajib format YYYY-MM.');return DB::transaction(function()use($companyId,$period,$user){DB::table('companies')->where('id',$companyId)->lockForUpdate()->first();$gl=GlPeriod::withoutGlobalScopes()->where('company_id',$companyId)->where('period',$period)->lockForUpdate()->firstOrFail();if($gl->status==='CLOSED')return $gl;$gl->update(['status'=>'CLOSED','closed_by'=>$user->id,'closed_at'=>now()]);$this->audit->record('close','gl_periods',documentId:$gl->id,after:['period'=>$period]);return $gl->fresh();});}
    public function trialBalance(int $companyId,string $period):array{if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$period))throw new RuntimeException('Period wajib format YYYY-MM.');return DB::table('journal_lines')->join('journals','journals.id','=','journal_lines.journal_id')->join('chart_of_accounts','chart_of_accounts.id','=','journal_lines.coa_id')->where('journals.company_id',$companyId)->where('journals.period',$period)->selectRaw('chart_of_accounts.code,chart_of_accounts.name,chart_of_accounts.type,chart_of_accounts.normal_balance,SUM(journal_lines.debit) total_debit,SUM(journal_lines.credit) total_credit')->groupBy('chart_of_accounts.id','chart_of_accounts.code','chart_of_accounts.name','chart_of_accounts.type','chart_of_accounts.normal_balance')->orderBy('chart_of_accounts.code')->get()->all();}
    private function assertAccess(User $user,int $companyId):void{if((int)$user->company_id!==$companyId&&!$user->companies()->whereKey($companyId)->exists())throw new RuntimeException('User tidak memiliki akses ke company Finance.');}
}
