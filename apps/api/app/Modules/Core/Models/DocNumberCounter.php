<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\Concerns\BelongsToCompany;

class DocNumberCounter extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'prefix', 'period_year', 'last_number'];
}
