<?php

namespace Modules\Finance\Services;

use Modules\Core\Models\User;
use Modules\Finance\Models\AccountMapping;
use Modules\Finance\Models\Journal;
use RuntimeException;

/**
 * BR-101: jurnal AUTO dari event operasional via account_mappings (deterministik).
 * Idempotent: event + dokumen sumber yang sama tidak menghasilkan jurnal ganda.
 * Mapping belum diisi → gagal jelas (tidak mengarang akun).
 */
class GlPostingService
{
    public function __construct(private JournalService $journals) {}

    /**
     * Post jurnal AUTO untuk satu event dokumen.
     * @return array{journal: Journal, created: bool}
     */
    public function postEvent(
        int $companyId,
        string $event,
        string $sourceDocumentType,
        int $sourceDocumentId,
        float $amount,
        string $period,
        User $user,
        ?string $description = null,
    ): array {
        if ($amount <= 0) {
            throw new RuntimeException("Jurnal AUTO [{$event}] memerlukan amount > 0.");
        }

        $mapping = AccountMapping::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('event', $event)->first();

        if ($mapping === null) {
            throw new RuntimeException("BR-101: account mapping untuk event [{$event}] belum diisi — lengkapi dulu di Finance → Account Mapping.");
        }

        // Idempotency: sudah ada jurnal POSTED untuk event+dokumen ini → kembalikan yang ada
        $existing = Journal::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('event', $event)
            ->where('source_document_type', $sourceDocumentType)
            ->where('source_document_id', $sourceDocumentId)
            ->where('status', 'POSTED')
            ->first();

        if ($existing !== null) {
            return ['journal' => $existing, 'created' => false];
        }

        $journal = $this->journals->post($companyId, [
            'period' => $period,
            'source' => 'AUTO',
            'event' => $event,
            'source_document_type' => $sourceDocumentType,
            'source_document_id' => $sourceDocumentId,
            'description' => $description ?? $event,
        ], [
            ['coa_id' => $mapping->debit_account_id, 'debit' => $amount, 'memo' => $description],
            ['coa_id' => $mapping->credit_account_id, 'credit' => $amount, 'memo' => $description],
        ], $user);

        return ['journal' => $journal, 'created' => true];
    }
}
