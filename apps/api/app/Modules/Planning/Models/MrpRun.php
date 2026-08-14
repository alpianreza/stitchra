<?php

namespace Modules\Planning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;

/** BR-043/045: setiap MRP run tersimpan sebagai versi; hasil = suggestion, bukan auto-PO */
class MrpRun extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'run_no', 'params', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['params' => 'array'];
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(MrpRequirement::class);
    }
}
