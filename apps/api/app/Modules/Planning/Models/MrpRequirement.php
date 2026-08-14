<?php

namespace Modules\Planning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\MasterData\Models\Material;

/** Hasil netting per material per run (BR-043). converted_to_pr = trace BR-120. */
class MrpRequirement extends Model
{
    protected $fillable = [
        'mrp_run_id', 'material_id', 'gross_qty', 'safety_stock_qty',
        'available_qty', 'on_order_qty', 'net_qty', 'uom_id',
        'need_date', 'converted_to_pr',
    ];

    protected function casts(): array
    {
        return [
            'gross_qty' => 'decimal:4', 'safety_stock_qty' => 'decimal:4',
            'available_qty' => 'decimal:4', 'on_order_qty' => 'decimal:4',
            'net_qty' => 'decimal:4', 'need_date' => 'date',
            'converted_to_pr' => 'boolean',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(MrpRun::class, 'mrp_run_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
