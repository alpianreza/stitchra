<?php
namespace Modules\Shipping\Models;
use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;use Modules\Core\Models\Concerns\BelongsToCompany;
class Container extends Model{use BelongsToCompany;protected $fillable=['company_id','shipment_id','container_no','size','seal_no','created_by','updated_by'];public function shipment():BelongsTo{return $this->belongsTo(Shipment::class);}}
