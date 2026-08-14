<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\Concerns\BelongsToCompany;

class Setting extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'key', 'value', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
