<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArInvoiceLine extends Model
{
    protected $fillable = ['ar_invoice_id', 'style_id', 'description', 'qty', 'unit_price', 'amount'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:4', 'unit_price' => 'decimal:6', 'amount' => 'decimal:4'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ArInvoice::class, 'ar_invoice_id');
    }
}
