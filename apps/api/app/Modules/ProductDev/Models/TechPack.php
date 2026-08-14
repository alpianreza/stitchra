<?php

namespace Modules\ProductDev\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\MasterData\Models\Style;

class TechPack extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'style_id', 'file_path', 'file_name', 'version', 'created_by'];

    public function style(): BelongsTo
    {
        return $this->belongsTo(Style::class);
    }
}
