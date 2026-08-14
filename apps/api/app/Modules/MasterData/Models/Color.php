<?php

namespace Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\Concerns\BelongsToCompany;

class Color extends Model
{
    use SoftDeletes, BelongsToCompany;

    protected $fillable = ['company_id', 'code', 'name', 'buyer_color_name', 'created_by', 'updated_by'];
}
