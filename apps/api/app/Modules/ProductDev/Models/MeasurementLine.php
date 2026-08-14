<?php

namespace Modules\ProductDev\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\MasterData\Models\Size;

class MeasurementLine extends Model
{
    protected $fillable = ['chart_id', 'pom_code', 'size_id', 'value', 'tolerance'];

    protected function casts(): array
    {
        return ['value' => 'decimal:3', 'tolerance' => 'decimal:3'];
    }

    public function chart(): BelongsTo
    {
        return $this->belongsTo(MeasurementChart::class, 'chart_id');
    }

    public function size(): BelongsTo
    {
        return $this->belongsTo(Size::class);
    }
}
