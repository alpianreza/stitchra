<?php

namespace Modules\Packing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;

/** Karton dengan barcode (carton_no unik per company) */
class Carton extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'carton_no', 'packing_list_id', 'seq',
        'gross_weight_kg', 'net_weight_kg', 'dimension',
    ];

    protected function casts(): array
    {
        return ['gross_weight_kg' => 'decimal:3', 'net_weight_kg' => 'decimal:3'];
    }

    public function packingList(): BelongsTo
    {
        return $this->belongsTo(PackingList::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CartonLine::class);
    }

    public function totalQty(): float
    {
        return (float) $this->lines()->sum('qty');
    }
}
