<?php

namespace Modules\ProductDev\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\MasterData\Models\Operation;

class RoutingOperation extends Model
{
    protected $fillable = ['routing_version_id', 'seq', 'operation_id', 'smv', 'machine_type'];

    protected function casts(): array
    {
        return ['smv' => 'decimal:4'];
    }

    public function routingVersion(): BelongsTo
    {
        return $this->belongsTo(RoutingVersion::class, 'routing_version_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }
}
