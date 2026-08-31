<?php

use Modules\MasterData\Models\DefectLibrary;
use Modules\Packing\Services\PackingService;
use Modules\Qc\Services\QcService;
use Modules\Shipping\Services\ShipmentService;

it('menolak QC cycle baru selama cycle sebelumnya masih pending',function(){[$user,,,, $mo]=qcFixture();$qc=app(QcService::class);$qc->create($mo,'FINAL',100,$user);expect(fn()=>$qc->create($mo,'FINAL',100,$user))->toThrow(RuntimeException::class,'setelah verdict REWORK');});

it('menolak defect inactive meskipun berasal dari company yang sama',function(){[$user,,,, $mo]=qcFixture();$defect=DefectLibrary::create(['company_id'=>1,'code'=>'OFF-'.uniqid(),'name'=>'Inactive','category'=>'OTHER','severity'=>'MINOR','is_active'=>false]);$inspection=app(QcService::class)->create($mo,'FINAL',100,$user);expect(fn()=>app(QcService::class)->recordDefects($inspection,[['defect_id'=>$defect->id,'qty'=>1]],$user))->toThrow(RuntimeException::class,'Defect aktif');});

it('menolak duplicate matrix di dalam satu carton',function(){[$user,,$style,$so,$mo,$colorway,$size]=packFixture();$pl=app(PackingService::class)->create($so,$mo->id,$user);$line=['style_id'=>$style->id,'colorway_id'=>$colorway->id,'size_id'=>$size->id,'qty'=>10];expect(fn()=>app(PackingService::class)->addCarton($pl,[],[$line,$line],$user))->toThrow(RuntimeException::class,'duplikat');});

it('satu packing list hanya dapat menghasilkan satu shipment',function(){[$user,,$style,$so,$mo,$colorway,$size,$fg]=packFixture();$qc=app(QcService::class);$qc->finalize($qc->create($mo,'FINAL',100,$user),$user);$packing=app(PackingService::class);$pl=$packing->create($so,$mo->id,$user);$packing->addCarton($pl,[],[['style_id'=>$style->id,'colorway_id'=>$colorway->id,'size_id'=>$size->id,'qty'=>100]],$user);$pl=$packing->finalize($pl->fresh(),$fg->id,$user);$shipping=app(ShipmentService::class);$shipping->create($pl,['ship_date'=>now()->toDateString()],$user);expect(fn()=>$shipping->create($pl->fresh(),['ship_date'=>now()->toDateString()],$user))->toThrow(RuntimeException::class,'sudah memiliki shipment');});
