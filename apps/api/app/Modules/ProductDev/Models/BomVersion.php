<?php

namespace Modules\ProductDev\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BomVersion extends Model
{
    public const STATUSES = ['DRAFT','SUBMITTED','APPROVED','OBSOLETE'];

    protected $fillable = ['bom_id', 'version_no', 'status', 'created_by', 'updated_by'];

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BomLine::class, 'bom_version_id');
    }
}
