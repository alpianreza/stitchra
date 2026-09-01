<?php

namespace Modules\Finance\Models;
use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;use Modules\Core\Models\Concerns\BelongsToCompany;use Modules\MasterData\Models\ChartOfAccount;
class AccountMapping extends Model{use BelongsToCompany;public const EVENTS=['GR_RECEIPT','MATERIAL_ISSUE','PRODUCTION_RECEIPT','SHIPMENT_COGS','AR_INVOICE','AR_TAX','AR_WITHHOLDING','AR_PAYMENT','AR_FX_GAIN','AR_FX_LOSS','AP_TAX','AP_WITHHOLDING','AP_PAYMENT','AP_FX_GAIN','AP_FX_LOSS','AR_FX_REVALUE_GAIN','AR_FX_REVALUE_LOSS','AP_FX_REVALUE_GAIN','AP_FX_REVALUE_LOSS','SUBCON_FEE'];protected $fillable=['company_id','event','debit_account_id','credit_account_id','created_by','updated_by'];public function debitAccount():BelongsTo{return$this->belongsTo(ChartOfAccount::class,'debit_account_id');}public function creditAccount():BelongsTo{return$this->belongsTo(ChartOfAccount::class,'credit_account_id');}}
