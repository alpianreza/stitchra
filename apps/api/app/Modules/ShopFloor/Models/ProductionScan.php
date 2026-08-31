<?php

namespace Modules\ShopFloor\Models;

use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;use LogicException;use Modules\Core\Models\Concerns\BelongsToCompany;use Modules\Cutting\Models\Bundle;use Modules\MasterData\Models\Employee;use Modules\MasterData\Models\Line;use Modules\MasterData\Models\Operation;use Modules\Production\Models\ProductionOrder;
class ProductionScan extends Model
{
 use BelongsToCompany;public const DIRECTIONS=['IN','OUT'];public const STAGES=['SEWING','FINISHING'];
 protected $fillable=['company_id','bundle_id','operation_id','production_order_id','line_id','employee_id','device_id','client_event_id','bundle_version','direction','stage','scanned_at','client_scanned_at','received_at','payload_hash'];
 protected $hidden=['payload_hash'];
 protected function casts():array{return['scanned_at'=>'datetime','client_scanned_at'=>'datetime','received_at'=>'datetime','bundle_version'=>'integer'];}
 protected static function booted():void{static::updating(fn()=>throw new LogicException('Production scan bersifat append-only.'));static::deleting(fn()=>throw new LogicException('Production scan bersifat append-only.'));}
 public function bundle():BelongsTo{return$this->belongsTo(Bundle::class);}public function operation():BelongsTo{return$this->belongsTo(Operation::class);}public function productionOrder():BelongsTo{return$this->belongsTo(ProductionOrder::class);}public function line():BelongsTo{return$this->belongsTo(Line::class);}public function employee():BelongsTo{return$this->belongsTo(Employee::class);}public function device():BelongsTo{return$this->belongsTo(ShopfloorDevice::class,'device_id');}
}
