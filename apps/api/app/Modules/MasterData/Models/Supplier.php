<?php

namespace Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\Concerns\BelongsToCompany;

class Supplier extends Model
{
    use SoftDeletes, BelongsToCompany;

    public const TYPES = ['FABRIC', 'TRIM', 'PACKAGING', 'SUBCON'];

    protected $fillable = [
        'company_id', 'code', 'name', 'type', 'lead_time_days', 'currency',
        'payment_term', 'address', 'contact', 'is_active', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function isSubcon(): bool
    {
        return $this->type === 'SUBCON';
    }
}
