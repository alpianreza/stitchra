<?php

namespace Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Concerns\BelongsToCompany;

class MaterialUomConversion extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'material_id', 'uom_id', 'rate_to_use_uom'];

    protected function casts(): array
    {
        return ['rate_to_use_uom' => 'decimal:6'];
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
