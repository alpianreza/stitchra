<?php

namespace Modules\ProductDev\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CostSheetLine extends Model
{
    public const TYPES = ['FABRIC','TRIM','CM','OVERHEAD','SUBCON','OTHER'];

    protected $fillable = ['cost_sheet_id', 'component_type', 'description', 'qty', 'rate', 'amount'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:6', 'rate' => 'decimal:6', 'amount' => 'decimal:4'];
    }

    public function costSheet(): BelongsTo
    {
        return $this->belongsTo(CostSheet::class);
    }
}
