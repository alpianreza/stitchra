<?php

namespace Modules\Shipping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\Packing\Models\PackingList;
use Modules\Sales\Models\DeliverySchedule;
use Modules\Sales\Models\SalesOrder;

class Shipment extends Model
{
    use BelongsToCompany;
    public const STATUSES=['DRAFT','READY','SHIPPED','CANCELLED']; public const TOLERANCE_CHECKS=['PENDING','OK','OVER','UNDER'];
    protected $fillable=['company_id','doc_no','sales_order_id','packing_list_id','delivery_schedule_id','ship_date','forwarder','booking_no','container_no','vessel_flight','port_of_loading','port_of_discharge','tolerance_check','over_tolerance_approved','status','created_by','updated_by'];
    protected function casts():array{return['ship_date'=>'date','over_tolerance_approved'=>'boolean'];}
    public function salesOrder():BelongsTo{return$this->belongsTo(SalesOrder::class);}public function packingList():BelongsTo{return$this->belongsTo(PackingList::class);}public function deliverySchedule():BelongsTo{return$this->belongsTo(DeliverySchedule::class);}public function lines():HasMany{return$this->hasMany(ShipmentLine::class);}public function containers():HasMany{return$this->hasMany(Container::class);}public function commercialInvoice():HasOne{return$this->hasOne(CommercialInvoice::class);}public function exportDocuments():HasMany{return$this->hasMany(ExportDocument::class);}
}
