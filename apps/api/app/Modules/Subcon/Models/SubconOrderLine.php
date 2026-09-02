<?php

namespace Modules\Subcon\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Cutting\Models\Bundle;
use Modules\MasterData\Models\Material;
use Modules\MasterData\Models\Uom;

class SubconOrderLine extends Model
{
    protected $fillable = [
        'subcon_order_id', 'material_id', 'bundle_id',
        'qty_sent', 'qty_returned', 'uom_id',
    ];

    protected function casts(): array
    {
        return ['qty_sent' => 'decimal:4', 'qty_returned' => 'decimal:4'];
    }

    public function subconOrder(): BelongsTo
    {
        return $this->belongsTo(SubconOrder::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Bundle::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class);
    }
}
