<?php

namespace Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationVersion extends Model
{
    protected $fillable = ['operation_id', 'version', 'smv', 'valid_from', 'created_by'];

    protected function casts(): array
    {
        return ['smv' => 'decimal:4', 'valid_from' => 'date'];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }
}
