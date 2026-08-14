<?php

namespace Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\Concerns\BelongsToCompany;

/**
 * BR-002: dual UOM — qty beli (KG/YDS) & qty pakai (MTR).
 * Konversi default per material di material_uom_conversions;
 * konversi FINAL tersimpan per roll saat GR (fabric_rolls.conversion_rate).
 * meter = kg × 1000 / (GSM × lebar_m).
 */
class Material extends Model
{
    use SoftDeletes, BelongsToCompany;

    public const TYPES = ['FABRIC', 'TRIM', 'PACKAGING'];
    public const TRACKING = ['ROLL', 'LOT'];

    protected $fillable = [
        'company_id', 'code', 'name', 'type', 'material_class',
        'composition', 'construction', 'gsm', 'width_cm', 'shrinkage_std_pct',
        'buy_uom_id', 'use_uom_id', 'tracking_level', 'safety_stock_qty',
        'is_active', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'gsm' => 'decimal:2',
            'width_cm' => 'decimal:2',
            'shrinkage_std_pct' => 'decimal:4',
            'safety_stock_qty' => 'decimal:4',
        ];
    }

    /** BR-002: konversi kg → meter. Mengembalikan null bila data GSM/lebar belum lengkap. */
    public function kgToMeter(float $kg): ?float
    {
        if (! $this->gsm || ! $this->width_cm || $this->gsm <= 0 || $this->width_cm <= 0) {
            return null;
        }

        $widthM = $this->width_cm / 100;

        return $kg * 1000 / ($this->gsm * $widthM);
    }

    public function isFabric(): bool
    {
        return $this->type === 'FABRIC';
    }

    public function isRollTracked(): bool
    {
        return $this->tracking_level === 'ROLL';
    }
}
