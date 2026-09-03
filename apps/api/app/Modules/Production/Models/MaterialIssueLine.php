<?php

namespace Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Models\StockReservation;
use Modules\MasterData\Models\Material;
use Modules\Receiving\Models\FabricRoll;

class MaterialIssueLine extends Model
{
    protected $fillable = [
        'material_issue_id', 'material_id', 'stock_reservation_id',
        'roll_id', 'lot_no', 'qty', 'uom_id', 'backflush_stage',
    ];

    protected function casts(): array { return ['qty' => 'decimal:4']; }
    public function materialIssue(): BelongsTo { return $this->belongsTo(MaterialIssue::class); }
    public function material(): BelongsTo { return $this->belongsTo(Material::class); }
    public function reservation(): BelongsTo { return $this->belongsTo(StockReservation::class, 'stock_reservation_id'); }
    public function roll(): BelongsTo { return $this->belongsTo(FabricRoll::class, 'roll_id'); }
}
