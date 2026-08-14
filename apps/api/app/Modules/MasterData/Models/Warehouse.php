<?php

namespace Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\Concerns\BelongsToCompany;

class Warehouse extends Model
{
    use SoftDeletes, BelongsToCompany;

    /** BR-090: SUBCON_VIRTUAL = lokasi virtual untuk bahan di subcontractor */
    public const TYPES = ['RM', 'WIP', 'FG', 'TRIM', 'SUBCON_VIRTUAL'];

    protected $fillable = [
        'company_id', 'factory_id', 'code', 'name', 'type',
        'is_active', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }
}
