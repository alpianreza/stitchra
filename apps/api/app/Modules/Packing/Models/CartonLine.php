<?php

namespace Modules\Packing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Isi karton: style×colorway×size×qty — dicek rasio vs SO matrix (BR-021/082) */
class CartonLine extends Model
{
    protected $fillable = ['carton_id', 'style_id', 'colorway_id', 'size_id', 'qty'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:4'];
    }

    public function carton(): BelongsTo
    {
        return $this->belongsTo(Carton::class);
    }
}
