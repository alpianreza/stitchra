<?php
namespace Modules\Cutting\Models;
use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;use Modules\Core\Models\Concerns\BelongsToCompany;use Modules\MasterData\Models\Uom;use Modules\Receiving\Models\FabricRoll;
class LayRoll extends Model { use BelongsToCompany; protected $fillable=['company_id','lay_id','fabric_roll_id','uom_id','qty_used','shade_override','created_by']; protected function casts():array{return['qty_used'=>'decimal:4','shade_override'=>'boolean'];} public function lay():BelongsTo{return $this->belongsTo(Lay::class);} public function fabricRoll():BelongsTo{return $this->belongsTo(FabricRoll::class);} public function uom():BelongsTo{return $this->belongsTo(Uom::class);} }
