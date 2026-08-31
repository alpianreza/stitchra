<?php

use Modules\Core\Models\User;
use Modules\Finance\Services\GlPostingService;
use Modules\Finance\Services\JournalService;

it('menolak journal date yang tidak berada pada period jurnal',function(){$user=User::factory()->create(['company_id'=>1]);$a=coa('1191','ASSET','DEBIT');$b=coa('4191','REVENUE','CREDIT');expect(fn()=>app(JournalService::class)->post(1,['period'=>'2026-08','journal_date'=>'2026-09-01'],[['coa_id'=>$a->id,'debit'=>10],['coa_id'=>$b->id,'credit'=>10]],$user))->toThrow(RuntimeException::class,'Journal date');});

it('menolak retry auto journal dengan source sama tetapi amount berbeda',function(){$user=User::factory()->create(['company_id'=>1]);glMappings();$gl=app(GlPostingService::class);$gl->postEvent(1,'GR_RECEIPT','goods_receipts',999,100,'2026-08',$user);expect(fn()=>$gl->postEvent(1,'GR_RECEIPT','goods_receipts',999,101,'2026-08',$user))->toThrow(RuntimeException::class,'amount berbeda');});

it('menolak reversal kedua pada jurnal yang sama',function(){$user=User::factory()->create(['company_id'=>1]);$a=coa('1192','ASSET','DEBIT');$b=coa('4192','REVENUE','CREDIT');$service=app(JournalService::class);$journal=$service->post(1,['period'=>'2026-08'],[['coa_id'=>$a->id,'debit'=>10],['coa_id'=>$b->id,'credit'=>10]],$user);$service->reverse($journal,$user);expect(fn()=>$service->reverse($journal->fresh(),$user))->toThrow(RuntimeException::class,'sudah direverse');});
