<?php

namespace Modules\Finance\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Finance\Models\AccountMapping;
use Modules\Finance\Models\Journal;
use RuntimeException;

class GlPostingService
{
    public function __construct(private JournalService $journals) {}

    public function postEvent(
        int $companyId,
        string $event,
        string $sourceType,
        int $sourceId,
        float $amount,
        string $period,
        User $user,
        ?string $description = null,
        ?string $journalDate = null,
    ): array {
        if (! in_array($event, AccountMapping::EVENTS, true)) throw new RuntimeException("Accounting event [{$event}] tidak memiliki authority terdaftar.");
        if ($amount <= 0) throw new RuntimeException("Jurnal AUTO [{$event}] memerlukan amount > 0.");
        if ($sourceId <= 0 || trim($sourceType) === '') throw new RuntimeException('Jurnal AUTO memerlukan source document yang valid.');
        $key = hash('sha256', implode('|', [$companyId, $event, $sourceType, $sourceId]));

        return DB::transaction(function () use ($companyId, $event, $sourceType, $sourceId, $amount, $period, $user, $description, $journalDate, $key): array {
            $company = DB::table('companies')->where('id', $companyId)->whereNull('deleted_at')->lockForUpdate()->first();
            if ($company === null || ! (bool) $company->is_active) throw new RuntimeException('Company Finance tidak aktif.');

            $existing = Journal::withoutGlobalScopes()->where('company_id', $companyId)->where('posting_key', $key)->first();
            if ($existing) {
                $amountConflict = abs((float) $existing->total_debit - $amount) > 0.0001;
                $periodConflict = (string) $existing->period !== $period;
                $dateConflict = $journalDate !== null && $existing->journal_date?->toDateString() !== $journalDate;
                if ($amountConflict || $periodConflict || $dateConflict) {
                    throw new RuntimeException('GL_IDEMPOTENCY_CONFLICT: source jurnal sama memiliki amount, period, atau journal date berbeda.');
                }
                return ['journal' => $existing, 'created' => false];
            }

            $mapping = AccountMapping::withoutGlobalScopes()->where('company_id', $companyId)->where('event', $event)->first();
            if (! $mapping) throw new RuntimeException("BR-101: account mapping untuk event [{$event}] belum diisi.");
            if ((int) $mapping->debit_account_id === (int) $mapping->credit_account_id) throw new RuntimeException('Debit dan credit mapping tidak boleh akun yang sama.');
            if (DB::table('chart_of_accounts')->where('company_id', $companyId)->whereNull('deleted_at')->where('is_active', true)
                ->whereIn('id', [$mapping->debit_account_id, $mapping->credit_account_id])->count() !== 2) {
                throw new RuntimeException('Account mapping menggunakan COA tidak aktif atau dari company lain.');
            }

            $journal = $this->journals->post($companyId, [
                'period' => $period,
                'journal_date' => $journalDate,
                'source' => 'AUTO',
                'event' => $event,
                'source_document_type' => $sourceType,
                'source_document_id' => $sourceId,
                'posting_key' => $key,
                'description' => $description ?? $event,
            ], [
                ['coa_id' => $mapping->debit_account_id, 'debit' => $amount],
                ['coa_id' => $mapping->credit_account_id, 'credit' => $amount],
            ], $user);

            return ['journal' => $journal, 'created' => true];
        });
    }
}
