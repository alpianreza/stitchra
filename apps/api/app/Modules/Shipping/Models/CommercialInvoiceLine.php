<?php
namespace Modules\Shipping\Models;
use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;
class CommercialInvoiceLine extends Model{protected $fillable=['commercial_invoice_id','shipment_line_id','style_id','colorway_id','size_id','qty','unit_price','amount'];protected function casts():array{return['qty'=>'decimal:4','unit_price'=>'decimal:6','amount'=>'decimal:4'];}public function invoice():BelongsTo{return$this->belongsTo(CommercialInvoice::class,'commercial_invoice_id');}public function shipmentLine():BelongsTo{return$this->belongsTo(ShipmentLine::class);}}
