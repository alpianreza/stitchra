<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\Concerns\BelongsToCompany;

class DocNumberingConfig extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'doc_type', 'prefix', 'digits', 'reset_yearly'];

    protected function casts(): array
    {
        return ['reset_yearly' => 'boolean'];
    }
}
