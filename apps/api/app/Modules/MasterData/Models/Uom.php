<?php

namespace Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Concerns\BelongsToCompany;

class Uom extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'code', 'name'];
}
