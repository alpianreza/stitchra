<?php

namespace Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\MasterData\Models\Material;
use Modules\MasterData\Models\Uom;

class RfqLine extends Model
{
    protected $fillable = ['rfq_id', 'line_no', 'material_id', 'qty', 'uom_id', 'pr_line_id'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:4'];
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class);
    }

    public function prLine(): BelongsTo
    {
        return $this->belongsTo(PrLine::class);
    }
}
