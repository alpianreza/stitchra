<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\MasterData\Models\Customer;

class Inquiry extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['OPEN','QUOTED','WON','LOST','CANCELLED'];

    protected $fillable = ['company_id', 'doc_no', 'customer_id', 'title', 'notes', 'status', 'created_by', 'updated_by'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
