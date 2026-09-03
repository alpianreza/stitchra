<?php
namespace Modules\Finance\Models;
use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;use Illuminate\Database\Eloquent\Relations\HasMany;use LogicException;use Modules\Core\Models\Concerns\BelongsToCompany;use Modules\Shipping\Models\Shipment;
class ShipmentCogs extends Model
{
 use BelongsToCompany;public $timestamps=false;protected $table='shipment_cogs';
 protected $fillable=['company_id','shipment_id','account_mapping_id','debit_account_id','credit_account_id','journal_id','event','base_cogs_total','currency','posting_date','gl_period','status','posting_key','source_hash','created_by','created_at'];
 protected function casts():array{return['base_cogs_total'=>'decimal:4','posting_date'=>'date','created_at'=>'datetime'];}
 protected static function booted():void{static::updating(fn()=>throw new LogicException('Shipment COGS is immutable.'));static::deleting(fn()=>throw new LogicException('Shipment COGS cannot be deleted.'));}
 public function shipment():BelongsTo{return$this->belongsTo(Shipment::class);}public function journal():BelongsTo{return$this->belongsTo(Journal::class);}public function lines():HasMany{return$this->hasMany(ShipmentCogsLine::class,'shipment_cogs_id');}
}
