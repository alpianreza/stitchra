<?php

namespace Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;use Illuminate\Database\Eloquent\Relations\HasMany;use LogicException;use Modules\Core\Models\Concerns\BelongsToCompany;use Modules\MasterData\Models\Line;use Modules\MasterData\Models\Style;use Modules\Planning\Models\CutPlan;use Modules\Planning\Models\LineLoading;use Modules\ProductDev\Models\BomVersion;use Modules\ProductDev\Models\CostSheet;use Modules\ProductDev\Models\RoutingVersion;use Modules\Sales\Models\SalesOrder;
class ProductionOrder extends Model
{
 use BelongsToCompany;public const STATUSES=['PLANNED','RELEASED','CUTTING','SEWING','FINISHING','QC','PACKED','CLOSED','CANCELLED'];
 protected $fillable=['company_id','doc_no','sales_order_id','style_id','bom_version_id','routing_version_id','standard_cost_sheet_id','standard_cost_snapshot','standard_cost_snapshot_hash','standard_cost_snapshotted_at','standard_cost_snapshot_source','line_id','qty_planned','qty_produced','planned_start','planned_end','actual_start','actual_end','status','created_by','updated_by'];
 protected function casts():array{return['qty_planned'=>'decimal:4','qty_produced'=>'decimal:4','planned_start'=>'date','planned_end'=>'date','actual_start'=>'date','actual_end'=>'date','standard_cost_snapshot'=>'array','standard_cost_snapshotted_at'=>'datetime'];}
 protected static function booted():void{static::updating(function(self$mo){if($mo->getOriginal('standard_cost_snapshot_hash')&&$mo->isDirty(['standard_cost_sheet_id','standard_cost_snapshot','standard_cost_snapshot_hash','standard_cost_snapshotted_at','standard_cost_snapshot_source']))throw new LogicException('Standard cost snapshot MO bersifat immutable.');});}
 public function salesOrder():BelongsTo{return$this->belongsTo(SalesOrder::class);}public function style():BelongsTo{return$this->belongsTo(Style::class);}public function bomVersion():BelongsTo{return$this->belongsTo(BomVersion::class);}public function routingVersion():BelongsTo{return$this->belongsTo(RoutingVersion::class);}public function standardCostSheet():BelongsTo{return$this->belongsTo(CostSheet::class,'standard_cost_sheet_id');}public function line():BelongsTo{return$this->belongsTo(Line::class);}public function matrixLines():HasMany{return$this->hasMany(MoLine::class);}public function lineLoadings():HasMany{return$this->hasMany(LineLoading::class);}public function cutPlans():HasMany{return$this->hasMany(CutPlan::class);}public function materialAllocations():HasMany{return$this->hasMany(MoMaterialAllocation::class);}
}
