<?php

namespace Modules\Cutting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Receiving\Models\FabricRoll;

/** Marker log — konsumsi kain aktual per roll (BR-031/041) */
class MarkerLog extends Model
{
    protected $fillable = [
        'cut_order_id', 'roll_id', 'marker_length_m', 'plies',
        'qty_fabric_used_m', 'efficiency_pct', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'marker_length_m' => 'decimal:3', 'qty_fabric_used_m' => 'decimal:4',
            'efficiency_pct' => 'decimal:4',
        ];
    }

    public function cutOrder(): BelongsTo
    {
        return $this->belongsTo(CutOrder::class);
    }

    public function roll(): BelongsTo
    {
        return $this->belongsTo(FabricRoll::class, 'roll_id');
    }
}
