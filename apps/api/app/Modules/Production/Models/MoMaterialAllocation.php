<?php

namespace Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\MasterData\Models\Material;

/** BR-060: alokasi material per MO — pasangan hard reservation saat release */
class MoMaterialAllocation extends Model
{
    protected $fillable = [
        'production_order_id', 'material_id', 'qty_required',
        'qty_reserved', 'qty_issued', 'is_backflush',
    ];

    protected function casts(): array
    {
        return [
            'qty_required' => 'decimal:4', 'qty_reserved' => 'decimal:4',
            'qty_issued' => 'decimal:4', 'is_backflush' => 'boolean',
        ];
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
