<?php

namespace Modules\Cutting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Receiving\Models\FabricRoll;

class MarkerLog extends Model
{
    protected $fillable = [
        'cut_order_id','roll_id','uom_id','marker_length_m','marker_length_use','plies',
        'qty_fabric_used_m','qty_fabric_used_use','efficiency_pct','created_by',
    ];
    protected function casts(): array
    {
        return ['marker_length_m'=>'decimal:3','marker_length_use'=>'decimal:4','qty_fabric_used_m'=>'decimal:4','qty_fabric_used_use'=>'decimal:4','efficiency_pct'=>'decimal:4'];
    }
    public function cutOrder(): BelongsTo { return $this->belongsTo(CutOrder::class); }
    public function roll(): BelongsTo { return $this->belongsTo(FabricRoll::class, 'roll_id'); }
}
