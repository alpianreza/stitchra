<?php

namespace Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\MasterData\Models\Supplier;

class Rfq extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['OPEN', 'CLOSED', 'AWARDED', 'CANCELLED'];

    protected $fillable = ['company_id', 'doc_no', 'status', 'deadline', 'notes', 'award_reason', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['deadline' => 'date'];
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RfqLine::class)->orderBy('line_no');
    }

    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class, 'rfq_suppliers')->withTimestamps();
    }

    public function purchaseOrder(): HasOne
    {
        return $this->hasOne(PurchaseOrder::class);
    }
}
