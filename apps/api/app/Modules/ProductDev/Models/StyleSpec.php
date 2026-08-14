<?php

namespace Modules\ProductDev\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\MasterData\Models\Style;

class StyleSpec extends Model
{
    protected $fillable = ['style_id', 'version', 'description', 'construction_notes'];

    public function style(): BelongsTo
    {
        return $this->belongsTo(Style::class);
    }
}
