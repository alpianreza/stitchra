<?php

namespace Modules\ProductDev\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\MasterData\Models\Colorway;
use Modules\MasterData\Models\Material;
use Modules\MasterData\Models\Uom;

/** BR-032: kolom BOM line; BR-031: estimated vs actual consumption terpisah */
class BomLine extends Model
{
    protected $fillable = [
        'bom_version_id', 'material_id', 'colorway_id', 'qty_per_pcs', 'uom_id',
        'wastage_pct', 'shrinkage_pct', 'consumption_estimated', 'consumption_actual',
        'is_backflush',
    ];

    protected function casts(): array
    {
        return [
            'qty_per_pcs' => 'decimal:6',
            'wastage_pct' => 'decimal:4',
            'shrinkage_pct' => 'decimal:4',
            'consumption_estimated' => 'decimal:6',
            'consumption_actual' => 'decimal:6',
            'is_backflush' => 'boolean',
        ];
    }

    /** Kebutuhan per pcs termasuk wastage + shrinkage (untuk MRP gross) */
    public function grossPerPcs(): float
    {
        $base = (float) ($this->consumption_estimated ?? $this->qty_per_pcs);

        return $base * (1 + (float) $this->wastage_pct / 100) * (1 + (float) $this->shrinkage_pct / 100);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function colorway(): BelongsTo
    {
        return $this->belongsTo(Colorway::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class);
    }
}
