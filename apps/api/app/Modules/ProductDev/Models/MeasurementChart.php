<?php

namespace Modules\ProductDev\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\MasterData\Models\Style;

class MeasurementChart extends Model
{
    protected $fillable = ['style_id', 'version'];

    public function style(): BelongsTo
    {
        return $this->belongsTo(Style::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MeasurementLine::class, 'chart_id');
    }
}
