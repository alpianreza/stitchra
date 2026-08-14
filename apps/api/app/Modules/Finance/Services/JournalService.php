<?php

namespace Modules\Finance\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Finance\Models\GlPeriod;
use Modules\Finance\Models\Journal;
use RuntimeException;

/**
 * BR-101: satu-satunya pintu posting jurnal. Jurnal WAJIB balanced (Σdebit = Σcredit).
 * BR-103: periode CLOSED menolak posting. Koreksi via reversal (jurnal balik), bukan edit.
 */
class JournalService
{
    private const BALANCE_TOLERANCE = 0.0001;

    public function __construct(
        private NumberingService $numbering,
        private AuditService $audit,
    ) {}

    /**
     * Post jurnal. $meta: period (YYYY-MM), journal_date, source, event?,
     * source_document_type?, source_document_id?, description?
     * $lines[]: coa_id, debit?, credit?, memo?
     */
    public function post(int $companyId, array $meta, array $lines, User $user): Journal
    {
        if (count($lines) < 2) {
            throw new RuntimeException('Jurnal wajib minimal 2 baris (debit & kredit).');
        }

        // Validasi per baris: debit XOR credit
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        foreach ($lines as $i => $line) {
            $debit = (float) ($line['debit'] ?? 0);
            $credit = (float) ($line['credit'] ?? 0);

            if (($debit > 0 && $credit > 0) || ($debit <= 0 && $credit <= 0)) {
                throw new RuntimeException("Baris {$i}: isi tepat satu sisi (debit XOR credit).");
            }
            $totalDebit += $debit;
            $totalCredit += $credit;
        }

        // BR-101: balanced
        if (abs($totalDebit - $totalCredit) > self::BALANCE_TOLERANCE) {
            throw new RuntimeException("BR-101: jurnal tidak balance — debit {$totalDebit} ≠ kredit {$totalCredit}.");
        }

        return DB::transaction(function () use ($companyId, $meta, $lines, $totalDebit, $totalCredit, $user): Journal {
            $period = $this->openPeriod($companyId, $meta['period']);

            $journal = Journal::create([
                'company_id' => $companyId,
                'doc_no' => $this->numbering->next($companyId, 'JE'),
                'period' => $period->period,
                'journal_date' => $meta['journal_date'] ?? now()->toDateString(),
                'source' => $meta['source'] ?? 'MANUAL',
                'event' => $meta['event'] ?? null,
                'source_document_type' => $meta['source_document_type'] ?? null,
                'source_document_id' => $meta['source_document_id'] ?? null,
                'description' => $meta['description'] ?? null,
                'total_debit' => round($totalDebit, 4),
                'total_credit' => round($totalCredit, 4),
                'status' => 'POSTED',
                'created_by' => $user->id,
            ]);

            foreach ($lines as $line) {
                $journal->lines()->create([
                    'coa_id' => $line['coa_id'],
                    'debit' => (float) ($line['debit'] ?? 0),
                    'credit' => (float) ($line['credit'] ?? 0),
                    'memo' => $line['memo'] ?? null,
                ]);
            }

            $this->audit->record('create', $journal, after: [
                'doc_no' => $journal->doc_no, 'total' => $journal->total_debit, 'event' => $journal->event,
            ]);

            return $journal->load('lines');
        });
    }

    /** Koreksi: buat jurnal balik; jurnal asli → VOID (tidak diedit/dihapus). */
    public function reverse(Journal $journal, User $user, ?string $reason = null): Journal
    {
        if ($journal->status === 'VOID') {
            throw new RuntimeException('Jurnal sudah VOID.');
        }

        return DB::transaction(function () use ($journal, $user, $reason): Journal {
            $reversalLines = $journal->lines->map(fn ($l) => [
                'coa_id' => $l->coa_id,
                'debit' => (float) $l->credit,   // dibalik
                'credit' => (float) $l->debit,
                'memo' => 'Reversal '.$journal->doc_no.($l->memo ? ' — '.$l->memo : ''),
            ])->all();

            $reversal = $this->post($journal->company_id, [
                'period' => $journal->period,
                'journal_date' => now()->toDateString(),
                'source' => $journal->source,
                'event' => $journal->event,
                'source_document_type' => $journal->source_document_type,
                'source_document_id' => $journal->source_document_id,
                'description' => 'REVERSAL '.$journal->doc_no.($reason ? ': '.$reason : ''),
            ], $reversalLines, $user);

            $reversal->update(['reverses_journal_id' => $journal->id]);
            $journal->update(['status' => 'VOID']);

            $this->audit->record('reverse', $journal, after: ['reversal' => $reversal->doc_no]);

            return $reversal;
        });
    }

    /** BR-103: periode harus OPEN; auto-create OPEN bila belum ada (tutup eksplisit). */
    private function openPeriod(int $companyId, string $period): GlPeriod
    {
        $glPeriod = GlPeriod::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('period', $period)
            ->lockForUpdate()
            ->first();

        if ($glPeriod === null) {
            $glPeriod = GlPeriod::create(['company_id' => $companyId, 'period' => $period, 'status' => 'OPEN']);
        }

        if ($glPeriod->status === 'CLOSED') {
            throw new RuntimeException("BR-103: periode {$period} sudah CLOSED — posting ditolak.");
        }

        return $glPeriod;
    }

    /** Tutup periode (BR-103). Tidak bisa dibuka kembali di versi ini. */
    public function closePeriod(int $companyId, string $period, User $user): GlPeriod
    {
        $glPeriod = GlPeriod::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('period', $period)->firstOrFail();

        $glPeriod->update(['status' => 'CLOSED', 'closed_by' => $user->id, 'closed_at' => now()]);

        $this->audit->record('close', 'gl_periods', documentId: $glPeriod->id, after: ['period' => $period]);

        return $glPeriod->fresh();
    }

    /** Trial balance per periode (agregasi dari journal_lines POSTED). */
    public function trialBalance(int $companyId, string $period): array
    {
        return DB::table('journal_lines')
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.coa_id')
            ->where('journals.company_id', $companyId)
            ->where('journals.period', $period)
            ->where('journals.status', 'POSTED')
            ->selectRaw('chart_of_accounts.code, chart_of_accounts.name, chart_of_accounts.type, chart_of_accounts.normal_balance, SUM(journal_lines.debit) as total_debit, SUM(journal_lines.credit) as total_credit')
            ->groupBy('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name', 'chart_of_accounts.type', 'chart_of_accounts.normal_balance')
            ->orderBy('chart_of_accounts.code')
            ->get()
            ->all();
    }
}
