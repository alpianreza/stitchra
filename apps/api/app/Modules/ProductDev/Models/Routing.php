<?php

namespace Modules\ProductDev\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\MasterData\Models\Style;

class Routing extends Model
{
    protected $fillable = ['style_id', 'current_version'];

    public function style(): BelongsTo
    {
        return $this->belongsTo(Style::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(RoutingVersion::class);
    }

    public function approvedVersion(): ?RoutingVersion
    {
        return $this->versions()->where('status', 'APPROVED')->orderByDesc('version_no')->first();
    }
}
