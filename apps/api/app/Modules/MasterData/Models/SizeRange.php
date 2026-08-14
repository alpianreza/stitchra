<?php

namespace Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Core\Models\Concerns\BelongsToCompany;

class SizeRange extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'code', 'name'];

    public function sizes(): BelongsToMany
    {
        return $this->belongsToMany(Size::class, 'size_range_lines')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }
}
