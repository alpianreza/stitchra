<?php

use Modules\Core\Models\User;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\MasterData\Models\Uom;
use Modules\MasterData\Models\Warehouse;
use Modules\Packing\Services\PackingService;
use Modules\ProductDev\Models\CostSheet;
use Modules\Qc\Services\QcService;
use Modules\Reporting\Services\DashboardService;
use Modules\Reporting\Services\ReportService;
use Modules\Shipping\Services\ShipmentService;

test('order status aggregates matrix value',function(){$user=User::factory()->create(['company_id'=>1]);[$style]=erpApprovedStyle($user);[$so]=erpConfirmedSo($user,$style,100,15);$report=app(ReportService::class)->run(1,'order_status');expect($report['rows'])->toHaveCount(1)->and($report['rows'][0]->doc_no)->toBe($so->doc_no)->and((float)$report['rows'][0]->total_value)->toBe(1500.0);});

test('consumption variance reads MO allocation without mutating BOM',function(){[, $fabric,,,,,$mo]=shopFixture();$mo->materialAllocations()->where('material_id',$fabric->id)->update(['qty_consumed'=>190,'actual_consumption_per_pcs'=>1.9]);$report=app(ReportService::class)->run(1,'consumption_variance');expect($report['rows'])->toHaveCount(1)->and((float)$report['rows'][0]->qty_per_pcs)->toBe(2.0)->and((float)$report['rows'][0]->consumption_actual)->toBe(1.9)->and((float)$report['rows'][0]->variance_pct)->toBe(-5.0);});

test('otd computes shipped date minus ex factory date',function(){[$user,,$style,$so,$mo,$colorway,$size,$fg]=packFixture();$so->update(['ex_factory_date'=>now()->subDays(2)->toDateString()]);$qc=app(QcService::class);$qc->finalize($qc->create($mo,'FINAL',100,$user),$user);$packing=app(PackingService::class);$pl=$packing->create($so->fresh(),$mo->id,$user);$packing->addCarton($pl,[],[['style_id'=>$style->id,'colorway_id'=>$colorway->id,'size_id'=>$size->id,'qty'=>100]],$user);$pl=$packing->finalize($pl->fresh(),$fg->id,$user);$shipping=app(ShipmentService::class);$shipping->ship($shipping->create($pl,['ship_date'=>now()->toDateString()],$user),$fg->id,$user);$row=app(ReportService::class)->run(1,'otd')['rows'][0];expect((int)$row->days_late)->toBe(2);});

test('BEP position uses one latest approved sheet per style',function(){[$user,,$style,$so,$mo,$colorway,$size,$fg]=packFixture();CostSheet::create(['company_id'=>1,'doc_no'=>'COST-'.uniqid(),'style_id'=>$style->id,'version'=>1,'fabric_cost'=>11.34,'trim_cost'=>0.25,'cm_cost'=>1.5,'overhead_cost'=>0.75,'fob_price'=>16,'status'=>'APPROVED','created_by'=>$user->id]);$qc=app(QcService::class);$qc->finalize($qc->create($mo,'FINAL',100,$user),$user);$packing=app(PackingService::class);$pl=$packing->create($so,$mo->id,$user);$packing->addCarton($pl,[],[['style_id'=>$style->id,'colorway_id'=>$colorway->id,'size_id'=>$size->id,'qty'=>100]],$user);$pl=$packing->finalize($pl->fresh(),$fg->id,$user);$ship=app(ShipmentService::class);$ship->ship($ship->create($pl,['ship_date'=>now()->toDateString()],$user),$fg->id,$user);$rows=app(ReportService::class)->run(1,'bep_position',['fixed_cost_share'=>100])['rows'];expect($rows)->toHaveCount(1)->and($rows[0]['bep_qty'])->toBe(47)->and($rows[0]['position'])->toBe('ABOVE_BEP');});

test('dashboard KPI matches fixture',function(){$user=User::factory()->create(['company_id'=>1]);[$style,$fabric,$uom]=erpApprovedStyle($user);erpConfirmedSo($user,$style,100,15);$warehouse=Warehouse::create(['company_id'=>1,'code'=>'RM-'.substr(uniqid(),-3),'name'=>'RM','type'=>'RM']);app(InventoryTransactionService::class)->post('OPENING',['company_id'=>1,'source_document_type'=>'tests','source_document_id'=>1],[['material_id'=>$fabric->id,'warehouse_id'=>$warehouse->id,'qty'=>250,'uom_id'=>$uom->id,'unit_cost'=>10]],$user);$kpi=app(DashboardService::class)->kpis(1,$user->id);expect($kpi['open_orders']['count'])->toBe(1)->and((float)$kpi['open_orders']['value'])->toBe(1500.0)->and((float)$kpi['stock_value'])->toBe(2500.0);});

test('CSV supports array rows and neutralizes spreadsheet formulas',function(){$csv=app(ReportService::class)->toCsv(['columns'=>['name','value'],'rows'=>[['name'=>'=CMD()','value'=>10]]]);expect($csv)->toContain("'=CMD()") ->and($csv)->toContain('10');});
