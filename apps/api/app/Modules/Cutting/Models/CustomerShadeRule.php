<?php
namespace Modules\Cutting\Models;
use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;use Modules\Core\Models\Concerns\BelongsToCompany;use Modules\MasterData\Models\Customer;
class CustomerShadeRule extends Model { use BelongsToCompany; protected $fillable=['company_id','customer_id','enabled','created_by','updated_by']; protected function casts():array{return['enabled'=>'boolean'];} public function customer():BelongsTo{return $this->belongsTo(Customer::class);} }
