<?php

namespace Modules\Shipping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\Inventory\Models\StockMovement;
use Modules\Packing\Models\PackingList;
use Modules\Production\Models\ProductionOrder;

class ShipmentInventoryValuation extends Model
{
    use BelongsToCompany;
    public $timestamps=false;
    protected $fillable=['company_id','shipment_id','shipment_line_id','packing_list_id','production_order_id','production_receipt_movement_id','shipment_movement_id','shipment_ledger_id','stock_balance_id','item_type','style_id','colorway_id','size_id','warehouse_id','ownership','shipment_quantity','moving_average_unit_cost','shipment_inventory_cost','currency','cost_method','valuation_event','valuation_version','on_hand_before','source_hash','created_by','valued_at','created_at'];
    protected function casts(): array{return['shipment_quantity'=>'decimal:4','moving_average_unit_cost'=>'decimal:6','shipment_inventory_cost'=>'decimal:4','on_hand_before'=>'decimal:4','valued_at'=>'datetime','created_at'=>'datetime'];}
    protected static function booted():void
    {
        static::updating(fn()=>throw new LogicException('Shipment inventory valuation is immutable.'));
        static::deleting(fn()=>throw new LogicException('Shipment inventory valuation cannot be deleted.'));
    }
    public function shipment():BelongsTo{return $this->belongsTo(Shipment::class);}
    public function line():BelongsTo{return $this->belongsTo(ShipmentLine::class,'shipment_line_id');}
    public function packingList():BelongsTo{return $this->belongsTo(PackingList::class);}
    public function productionOrder():BelongsTo{return $this->belongsTo(ProductionOrder::class);}
    public function shipmentMovement():BelongsTo{return $this->belongsTo(StockMovement::class,'shipment_movement_id');}
}
