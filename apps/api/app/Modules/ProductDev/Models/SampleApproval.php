<?php

namespace Modules\ProductDev\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SampleApproval extends Model
{
    protected $fillable = ['sample_id', 'status', 'comment', 'by_name', 'recorded_by', 'response_reference', 'request_key'];

    protected $hidden = ['request_key'];

    public function sample(): BelongsTo
    {
        return $this->belongsTo(Sample::class);
    }
}
