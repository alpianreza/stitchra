<?php
namespace Modules\Finance\Models;
use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;use LogicException;use Modules\Core\Models\Concerns\BelongsToCompany;use Modules\Shipping\Models\ShipmentInventoryValuation;
class ShipmentCogsLine extends Model
{
 use BelongsToCompany;public $timestamps=false;protected $fillable=['shipment_cogs_id','company_id','shipment_line_id','shipment_inventory_valuation_id','shipment_movement_id','shipment_ledger_id','production_receipt_movement_id','quantity','unit_cost','base_cogs','currency','d08_valuation_version','d08_source_hash','source_hash','created_at'];
 protected function casts():array{return['quantity'=>'decimal:4','unit_cost'=>'decimal:6','base_cogs'=>'decimal:4','created_at'=>'datetime'];}
 protected static function booted():void{static::updating(fn()=>throw new LogicException('Shipment COGS line is immutable.'));static::deleting(fn()=>throw new LogicException('Shipment COGS line cannot be deleted.'));}
 public function cogs():BelongsTo{return$this->belongsTo(ShipmentCogs::class,'shipment_cogs_id');}public function d08():BelongsTo{return$this->belongsTo(ShipmentInventoryValuation::class,'shipment_inventory_valuation_id');}
}
