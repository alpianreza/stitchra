<?php

namespace Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\Concerns\BelongsToCompany;

class Operation extends Model
{
    use SoftDeletes, BelongsToCompany;

    protected $fillable = [
        'company_id', 'code', 'name', 'machine_type', 'grade',
        'is_active', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(OperationVersion::class);
    }

    /** SMV aktif pada tanggal tertentu (BR-033: versioned) */
    public function smvAt(?string $date = null): ?float
    {
        $date = $date ?? now()->toDateString();

        $version = $this->versions()
            ->where('valid_from', '<=', $date)
            ->orderByDesc('valid_from')
            ->orderByDesc('version')
            ->first();

        return $version ? (float) $version->smv : null;
    }
}
