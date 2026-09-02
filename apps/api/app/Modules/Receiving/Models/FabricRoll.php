<?php

namespace Modules\Receiving\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\Cutting\Models\LayRoll;
use Modules\MasterData\Models\Material;
use Modules\MasterData\Models\ShadeGroup;

class FabricRoll extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['QUALITY_HOLD','RELEASED','REJECTED_RETURNED','CONSUMED'];

    protected $fillable = [
        'company_id','roll_no','gr_line_id','material_id','use_uom_id','lot_no','shade_group_id',
        'qty_buy','qty_meter_actual','qty_use_actual','conversion_rate','gsm_actual','width_actual_cm',
        'qty_remaining_meter','qty_remaining_use','status',
    ];

    protected function casts(): array
    {
        return [
            'qty_buy'=>'decimal:4','qty_meter_actual'=>'decimal:4','qty_use_actual'=>'decimal:4',
            'conversion_rate'=>'decimal:6','gsm_actual'=>'decimal:2','width_actual_cm'=>'decimal:2',
            'qty_remaining_meter'=>'decimal:4','qty_remaining_use'=>'decimal:4',
        ];
    }

    public function material(): BelongsTo { return $this->belongsTo(Material::class); }
    public function shadeGroup(): BelongsTo { return $this->belongsTo(ShadeGroup::class); }
    public function layRolls(): HasMany { return $this->hasMany(LayRoll::class); }
    public function remainingUse(): float { return (float) ($this->qty_remaining_use ?? $this->qty_remaining_meter); }

    public function consumeUse(float $qtyUse, float $qtyMeters): void
    {
        $this->qty_remaining_use = max(0, $this->remainingUse() - $qtyUse);
        $this->qty_remaining_meter = max(0, (float) $this->qty_remaining_meter - $qtyMeters);
        if ((float) $this->qty_remaining_use <= 0.0001) $this->status = 'CONSUMED';
        $this->save();
    }
}
