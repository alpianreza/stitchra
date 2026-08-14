<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\MasterData\Models\ChartOfAccount;

/** Mapping event operasional → akun debit/kredit untuk jurnal AUTO (BR-101) */
class AccountMapping extends Model
{
    use BelongsToCompany;

    public const EVENTS = [
        'GR_RECEIPT',       // Persediaan RM ↑ / Utang AP ↑
        'MATERIAL_ISSUE',   // WIP ↑ / Persediaan RM ↓
        'PRODUCTION_RECEIPT', // Persediaan FG ↑ / WIP ↓
        'SHIPMENT_COGS',    // HPP ↑ / Persediaan FG ↓
        'AR_INVOICE',       // Piutang ↑ / Pendapatan ↑
        'AR_PAYMENT',       // Kas/Bank ↑ / Piutang ↓
        'AP_PAYMENT',       // Utang AP ↓ / Kas/Bank ↓
        'SUBCON_FEE',       // Biaya subcon ↑ / Utang AP ↑
    ];

    protected $fillable = [
        'company_id', 'event', 'debit_account_id', 'credit_account_id',
        'created_by', 'updated_by',
    ];

    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'debit_account_id');
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'credit_account_id');
    }
}
