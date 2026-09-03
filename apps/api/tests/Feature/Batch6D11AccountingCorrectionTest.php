<?php

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\ApprovalFlow;
use Modules\Core\Models\Role;
use Modules\Finance\Models\GlPeriod;
use Modules\Finance\Models\Journal;
use Modules\Finance\Services\AccountingCorrectionService;
use Modules\Finance\Services\ShipmentCogsService;

function d11Approval($user):void
{
    $role=Role::create(['company_id'=>1,'code'=>'D11-'.uniqid(),'name'=>'D11 Approver']);$user->roles()->attach($role->id);
    $flow=ApprovalFlow::create(['company_id'=>1,'doc_type'=>'ACCOUNTING_CORRECTION','version'=>1,'mode'=>'sequential','is_active'=>true]);
    DB::table('approval_flow_steps')->insert(['flow_id'=>$flow->id,'step_no'=>1,'role_id'=>$role->id,'created_at'=>now(),'updated_at'=>now()]);
}
function d11Approved(float$corrected,string$date='2026-09-03'):array
{
    [$user,$shipment]=d10Fixture(10,$date);$original=app(ShipmentCogsService::class)->post($shipment,$user)['cogs']->journal;d11Approval($user);
    $service=app(AccountingCorrectionService::class);$c=$service->request($original,$corrected,'Authoritative correction',1,$user)['correction'];$c=$service->approve($c,$user);
    return[$user,$original->fresh('lines'),$c,$service];
}

it('OPEN creates atomic append-only reversal and corrected repost without mutating original',function(){
    [$user,$original,$c,$service]=d11Approved(1250);$before=$original->toArray();$posted=$service->post($c,$user)['correction'];$after=$original->fresh('lines');
    expect($after->status)->toBe('POSTED')->and($after->period)->toBe($before['period'])->and((float)$after->total_debit)->toBe((float)$before['total_debit'])
        ->and($posted->reversalJournal->reverses_journal_id)->toBe($original->id)->and((float)$posted->reversalJournal->total_debit)->toBe((float)$original->total_credit)
        ->and((float)$posted->correctedJournal->total_debit)->toBe(1250.0)->and($posted->reversalJournal->source_document_id)->toBe($posted->id)
        ->and($posted->correctedJournal->source_document_id)->toBe($posted->id);
});

it('OPEN rejects corrected zero before creating reversal-only partial state',function(){
    [$user,$shipment]=d10Fixture();$original=app(ShipmentCogsService::class)->post($shipment,$user)['cogs']->journal;d11Approval($user);$count=Journal::withoutGlobalScopes()->count();
    expect(fn()=>app(AccountingCorrectionService::class)->request($original,0,'Cannot leave reversal only',1,$user))->toThrow(RuntimeException::class,'mandatory corrected repost');
    expect(Journal::withoutGlobalScopes()->count())->toBe($count)->and(DB::table('accounting_corrections')->count())->toBe(0);
});

it('CLOSED preserves history and posts approved difference in current OPEN period',function(){
    [$user,$shipment]=d10Fixture(10,'2026-08-31');$original=app(ShipmentCogsService::class)->post($shipment,$user)['cogs']->journal;
    GlPeriod::withoutGlobalScopes()->where('company_id',1)->where('period','2026-08')->update(['status'=>'CLOSED']);GlPeriod::withoutGlobalScopes()->updateOrCreate(['company_id'=>1,'period'=>now()->format('Y-m')],['status'=>'OPEN']);d11Approval($user);
    $service=app(AccountingCorrectionService::class);$c=$service->request($original,900,'Closed-period correction',1,$user)['correction'];expect(fn()=>$service->post($c,$user))->toThrow(RuntimeException::class,'approval');
    $c=$service->approve($c,$user);$posted=$service->post($c,$user)['correction'];
    expect($original->fresh()->status)->toBe('POSTED')->and(GlPeriod::withoutGlobalScopes()->where('company_id',1)->where('period','2026-08')->value('status'))->toBe('CLOSED')
        ->and($posted->reversal_journal_id)->toBeNull()->and($posted->corrected_journal_id)->toBeNull()->and($posted->adjustmentJournal->period)->toBe(now()->format('Y-m'))
        ->and((float)$posted->adjustmentJournal->total_debit)->toBe(abs((float)$posted->adjustment_amount));
});

it('CLOSED NO_CHANGE needs no current period approval or journal',function(){
    [$user,$shipment]=d10Fixture(10,'2026-08-31');$original=app(ShipmentCogsService::class)->post($shipment,$user)['cogs']->journal;
    GlPeriod::withoutGlobalScopes()->where('company_id',1)->where('period','2026-08')->update(['status'=>'CLOSED']);GlPeriod::withoutGlobalScopes()->where('company_id',1)->where('period',now()->format('Y-m'))->delete();$count=Journal::withoutGlobalScopes()->count();
    $r=app(AccountingCorrectionService::class)->request($original,(float)$original->total_debit,'Closed no delta',1,$user)['correction'];
    expect($r->status)->toBe('NO_CHANGE')->and($r->adjustment_period)->toBeNull()->and($r->approval_request_id)->toBeNull()->and(Journal::withoutGlobalScopes()->count())->toBe($count);
});

it('returns NO_CHANGE and creates no approval or journal',function(){
    [$user,$shipment]=d10Fixture();$original=app(ShipmentCogsService::class)->post($shipment,$user)['cogs']->journal;$count=Journal::withoutGlobalScopes()->count();
    $r=app(AccountingCorrectionService::class)->request($original,(float)$original->total_debit,'No delta',1,$user)['correction'];
    expect($r->status)->toBe('NO_CHANGE')->and($r->approval_request_id)->toBeNull()->and(Journal::withoutGlobalScopes()->count())->toBe($count);
});

it('rolls back before any reversal when approved source changes',function(){
    [$user,$original,$c,$service]=d11Approved(1250);$count=Journal::withoutGlobalScopes()->count();
    DB::table('account_mappings')->where('id',$c->account_mapping_id)->update(['debit_account_id'=>$c->credit_account_id,'credit_account_id'=>$c->debit_account_id]);
    expect(fn()=>$service->post($c,$user))->toThrow(RuntimeException::class,'source changed');
    expect(Journal::withoutGlobalScopes()->count())->toBe($count)->and(Journal::withoutGlobalScopes()->where('reverses_journal_id',$original->id)->count())->toBe(0);
});

it('is idempotent and rejects a changed payload for the same version',function(){
    [$user,$shipment]=d10Fixture();$original=app(ShipmentCogsService::class)->post($shipment,$user)['cogs']->journal;d11Approval($user);$service=app(AccountingCorrectionService::class);
    $a=$service->request($original,1100,'Same request',1,$user);$b=$service->request($original,1100,'Same request',1,$user);
    expect($a['created'])->toBeTrue()->and($b['created'])->toBeFalse()->and($b['correction']->id)->toBe($a['correction']->id);
    expect(fn()=>$service->request($original,1200,'Changed request',1,$user))->toThrow(RuntimeException::class,'IDEMPOTENCY CONFLICT');
});

it('fails closed for unsupported valuation sources without accounting journal handoff',function(){
    [$user,$shipment]=d10Fixture();$journal=app(ShipmentCogsService::class)->post($shipment,$user)['cogs']->journal;
    DB::table('journals')->where('id',$journal->id)->update(['event'=>'D09_VARIANCE','source_document_type'=>'fg_actual_costings']);
    expect(fn()=>app(AccountingCorrectionService::class)->request($journal->fresh('lines'),1200,'Unsupported source',1,$user))->toThrow(RuntimeException::class,'no supported authoritative');
});

it('blocks legacy direct reversal for D-10 and D-11 chain journals',function(){
    $controller=file_get_contents(app_path('Modules/Finance/Http/Controllers/JournalController.php'));
    expect($controller)->toContain('extends Controller')->toContain('BR-109')->toContain("event==='SHIPMENT_COGS'")->toContain("source_document_type==='accounting_corrections'");
});

it('contains no closed-period reopening legacy reverse or historical backfill',function(){
    $source=file_get_contents(app_path('Modules/Finance/Services/AccountingCorrectionService.php'));
    expect($source)->toContain('ApprovalEngine')->toContain('JournalService')->not->toContain('reverseIntoPeriod')->not->toContain("update(['status'=>'OPEN'])")->not->toContain('retained earnings');
    $migration=file_get_contents(database_path('migrations/2026_09_03_000034_add_d11_accounting_corrections.php'));
    expect($migration)->not->toContain('DB::table(')->not->toContain('->update(');
});
