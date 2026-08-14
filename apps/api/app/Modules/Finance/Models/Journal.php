<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;

/** BR-101: jurnal balanced (Σdebit = Σcredit, divalidasi JournalService); koreksi via reversal */
class Journal extends Model
{
    use BelongsToCompany;

    public const SOURCES = ['AUTO', 'MANUAL'];
    public const STATUSES = ['POSTED', 'VOID'];

    protected $fillable = [
        'company_id', 'doc_no', 'period', 'journal_date', 'source', 'event',
        'source_document_type', 'source_document_id', 'description',
        'total_debit', 'total_credit', 'status', 'reverses_journal_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'journal_date' => 'date',
            'total_debit' => 'decimal:4', 'total_credit' => 'decimal:4',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_journal_id');
    }
}
