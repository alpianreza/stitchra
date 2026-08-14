<?php

namespace Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\Concerns\BelongsToCompany;

class ShadeGroup extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'code', 'name'];
}
