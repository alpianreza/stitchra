<?php

namespace Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Concerns\BelongsToCompany;

class Colorway extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'style_id', 'color_id', 'lab_dip_ref', 'shade_group_id'];

    public function style(): BelongsTo
    {
        return $this->belongsTo(Style::class);
    }

    public function color(): BelongsTo
    {
        return $this->belongsTo(Color::class);
    }

    public function shadeGroup(): BelongsTo
    {
        return $this->belongsTo(ShadeGroup::class);
    }
}
