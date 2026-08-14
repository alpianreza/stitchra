<?php

namespace Modules\Receiving\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\MasterData\Models\Material;
use Modules\Purchasing\Models\PoLine;

/** BR-004: line masuk QUALITY_HOLD; BR-052: fabric → satu fabric_rolls per roll */
class GrLine extends Model
{
    public const STATUSES = ['QUALITY_HOLD','RELEASED','REJECTED_RETURNED'];

    protected $fillable = [
        'goods_receipt_id', 'po_line_id', 'material_id',
        'qty_received', 'uom_id', 'unit_price', 'status',
    ];

    protected function casts(): array
    {
        return ['qty_received' => 'decimal:4', 'unit_price' => 'decimal:6'];
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function poLine(): BelongsTo
    {
        return $this->belongsTo(PoLine::class, 'po_line_id');
    }

    public function rolls(): HasMany
    {
        return $this->hasMany(FabricRoll::class, 'gr_line_id');
    }
}
