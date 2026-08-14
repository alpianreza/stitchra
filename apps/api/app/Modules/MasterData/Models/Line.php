<?php

namespace Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\Concerns\BelongsToCompany;

class Line extends Model
{
    use SoftDeletes, BelongsToCompany;

    protected $fillable = [
        'company_id', 'factory_id', 'code', 'name', 'section',
        'capacity_std', 'manpower_std', 'is_active', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function machines(): HasMany
    {
        return $this->hasMany(Machine::class);
    }

    public function costRates(): HasMany
    {
        return $this->hasMany(LineCostRate::class);
    }
}
