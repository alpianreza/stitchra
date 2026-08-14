<?php

namespace Modules\ProductDev\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SampleApproval extends Model
{
    protected $fillable = ['sample_id', 'status', 'comment', 'by_name'];

    public function sample(): BelongsTo
    {
        return $this->belongsTo(Sample::class);
    }
}
