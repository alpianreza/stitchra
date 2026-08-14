<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\DocNumberCounter;
use Modules\Core\Models\DocNumberingConfig;
use RuntimeException;

/**
 * BR-010: Nomor dokumen PREFIX-YYYY-NNNNNN per company.
 * - Counter terpisah per (company, prefix, tahun)
 * - Concurrency-safe: row lock (SELECT ... FOR UPDATE) dalam transaksi
 * - Nomor dokumen batal TIDAK di-reuse (gap diperbolehkan & tercatat)
 */
class NumberingService
{
    /**
     * Ambil nomor berikutnya untuk doc-type. WAJIB dipanggil di dalam transaksi
     * pembuatan dokumen (atomic bersama insert dokumen — konsisten BR-013 untuk stok).
     */
    public function next(int $companyId, string $docType): string
    {
        $config = DocNumberingConfig::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('doc_type', $docType)
            ->first();

        if ($config === null) {
            throw new RuntimeException("Numbering config belum ada untuk doc_type [{$docType}] di company [{$companyId}].");
        }

        $year = (int) now()->year;

        return DB::transaction(function () use ($companyId, $config, $year): string {
            // Lock baris counter (atau buat lalu lock) — mencegah duplikat saat paralel.
            $counter = DocNumberCounter::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('prefix', $config->prefix)
                ->where('period_year', $year)
                ->lockForUpdate()
                ->first();

            if ($counter === null) {
                $counter = new DocNumberCounter([
                    'company_id' => $companyId,
                    'prefix' => $config->prefix,
                    'period_year' => $year,
                    'last_number' => 0,
                ]);
            }

            $counter->last_number++;
            $counter->save();

            $seq = str_pad((string) $counter->last_number, $config->digits, '0', STR_PAD_LEFT);

            return "{$config->prefix}-{$year}-{$seq}";
        });
    }

    /** Preview nomor berikutnya TANPA meng-increment (untuk UI). */
    public function peek(int $companyId, string $docType): ?string
    {
        $config = DocNumberingConfig::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('doc_type', $docType)
            ->first();

        if ($config === null) {
            return null;
        }

        $year = (int) now()->year;
        $last = DocNumberCounter::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('prefix', $config->prefix)
            ->where('period_year', $year)
            ->value('last_number') ?? 0;

        $seq = str_pad((string) ($last + 1), $config->digits, '0', STR_PAD_LEFT);

        return "{$config->prefix}-{$year}-{$seq}";
    }
}
