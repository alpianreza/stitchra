<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;use Modules\Core\Models\Concerns\BelongsToCompany;use Modules\Purchasing\Models\SupplierInvoice;
class ApPayment extends Model{use BelongsToCompany;protected $fillable=['company_id','doc_no','supplier_invoice_id','payment_date','currency_id','exchange_rate','amount','base_amount','realized_fx_amount','method','reference_no','created_by'];protected function casts():array{return['payment_date'=>'date','exchange_rate'=>'decimal:6','amount'=>'decimal:4','base_amount'=>'decimal:4','realized_fx_amount'=>'decimal:4'];}public function supplierInvoice():BelongsTo{return$this->belongsTo(SupplierInvoice::class);}}
