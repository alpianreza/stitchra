<?php

namespace Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\Concerns\BelongsToCompany;

class Employee extends Model
{
    use SoftDeletes, BelongsToCompany;

    protected $fillable = [
        'company_id', 'nik', 'name', 'section', 'line_id', 'skill',
        'is_operator', 'is_active', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['is_operator' => 'boolean', 'is_active' => 'boolean'];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(Line::class);
    }
}
