<?php

namespace Modules\ProductDev\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoutingVersion extends Model
{
    public const STATUSES = ['DRAFT','SUBMITTED','APPROVED','OBSOLETE'];

    protected $fillable = ['routing_id', 'version_no', 'total_sam', 'status', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['total_sam' => 'decimal:4'];
    }

    public function routing(): BelongsTo
    {
        return $this->belongsTo(Routing::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(RoutingOperation::class, 'routing_version_id')->orderBy('seq');
    }
}
