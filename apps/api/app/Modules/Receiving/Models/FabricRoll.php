<?php

namespace Modules\Receiving\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\MasterData\Models\Material;
use Modules\MasterData\Models\ShadeGroup;

/**
 * BR-003/052: satu baris per roll. BR-002: qty beli + meter aktual + conversion_rate
 * tersimpan per roll. BR-042: leftover = qty_remaining_meter kembali ke inventory.
 */
class FabricRoll extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['QUALITY_HOLD','RELEASED','REJECTED_RETURNED','CONSUMED'];

    protected $fillable = [
        'company_id', 'roll_no', 'gr_line_id', 'material_id', 'lot_no',
        'shade_group_id', 'qty_buy', 'qty_meter_actual', 'conversion_rate',
        'gsm_actual', 'width_actual_cm', 'qty_remaining_meter', 'status',
    ];

    protected function casts(): array
    {
        return [
            'qty_buy' => 'decimal:4', 'qty_meter_actual' => 'decimal:4',
            'conversion_rate' => 'decimal:6', 'gsm_actual' => 'decimal:2',
            'width_actual_cm' => 'decimal:2', 'qty_remaining_meter' => 'decimal:4',
        ];
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function shadeGroup(): BelongsTo
    {
        return $this->belongsTo(ShadeGroup::class);
    }

    /** BR-042: pemakaian mengurangi sisa; sisa = leftover */
    public function consume(float $meters): void
    {
        $this->qty_remaining_meter = max(0, (float) $this->qty_remaining_meter - $meters);
        if ((float) $this->qty_remaining_meter <= 0) {
            $this->status = 'CONSUMED';
        }
        $this->save();
    }
}
