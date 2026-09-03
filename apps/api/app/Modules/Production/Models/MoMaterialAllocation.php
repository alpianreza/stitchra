<?php

namespace Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\MasterData\Models\Material;
use Modules\MasterData\Models\Uom;

class MoMaterialAllocation extends Model
{
    protected $fillable = [
        'production_order_id', 'material_id', 'uom_id', 'qty_required', 'qty_reserved',
        'qty_issued', 'qty_consumed', 'actual_consumption_per_pcs', 'is_backflush', 'backflush_stage',
    ];

    protected function casts(): array
    {
        return [
            'qty_required' => 'decimal:4', 'qty_reserved' => 'decimal:4',
            'qty_issued' => 'decimal:4', 'qty_consumed' => 'decimal:4',
            'actual_consumption_per_pcs' => 'decimal:6', 'is_backflush' => 'boolean',
        ];
    }

    public function productionOrder(): BelongsTo { return $this->belongsTo(ProductionOrder::class); }
    public function material(): BelongsTo { return $this->belongsTo(Material::class); }
    public function uom(): BelongsTo { return $this->belongsTo(Uom::class); }
}
