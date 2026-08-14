<?php

use Modules\Core\Models\User;
use Modules\Finance\Models\GlPeriod;
use Modules\Finance\Models\Journal;
use Modules\Finance\Services\JournalService;
use Modules\MasterData\Models\ChartOfAccount;

function coa(string $code, string $type, string $normal): ChartOfAccount
{
    return ChartOfAccount::create([
        'company_id' => 1, 'code' => $code, 'name' => $code, 'type' => $type, 'normal_balance' => $normal,
    ]);
}

test('BR-101: jurnal tidak balance DITOLAK', function () {
    $user = User::factory()->create(['company_id' => 1]);
    $kas = coa('1101', 'ASSET', 'DEBIT');
    $pendapatan = coa('4101', 'REVENUE', 'CREDIT');

    app(JournalService::class)->post(1, ['period' => '2026-08'], [
        ['coa_id' => $kas->id, 'debit' => 1000],
        ['coa_id' => $pendapatan->id, 'credit' => 900],   // tidak balance
    ], $user);
})->throws(RuntimeException::class);

test('baris jurnal wajib debit XOR credit', function () {
    $user = User::factory()->create(['company_id' => 1]);
    $kas = coa('1101', 'ASSET', 'DEBIT');
    $pendapatan = coa('4101', 'REVENUE', 'CREDIT');

    // Dua sisi terisi sekaligus → tolak
    app(JournalService::class)->post(1, ['period' => '2026-08'], [
        ['coa_id' => $kas->id, 'debit' => 100, 'credit' => 100],
        ['coa_id' => $pendapatan->id, 'credit' => 100],
    ], $user);
})->throws(RuntimeException::class);

test('jurnal valid terposting balanced dengan nomor JE (BR-010/101)', function () {
    $user = User::factory()->create(['company_id' => 1]);
    $kas = coa('1101', 'ASSET', 'DEBIT');
    $pendapatan = coa('4101', 'REVENUE', 'CREDIT');

    $journal = app(JournalService::class)->post(1, ['period' => '2026-08', 'description' => 'Penjualan tunai'], [
        ['coa_id' => $kas->id, 'debit' => 1500000],
        ['coa_id' => $pendapatan->id, 'credit' => 1500000],
    ], $user);

    $year = now()->year;
    expect($journal->doc_no)->toBe("JE-{$year}-000001");
    expect((float) $journal->total_debit)->toBe(1500000.0);
    expect((float) $journal->total_credit)->toBe(1500000.0);
    expect($journal->status)->toBe('POSTED');
    expect($journal->lines)->toHaveCount(2);

    // Periode auto-create OPEN
    expect(GlPeriod::withoutGlobalScopes()->where('period', '2026-08')->first()->status)->toBe('OPEN');
});

test('BR-103: periode CLOSED menolak posting', function () {
    $user = User::factory()->create(['company_id' => 1]);
    $kas = coa('1101', 'ASSET', 'DEBIT');
    $pendapatan = coa('4101', 'REVENUE', 'CREDIT');

    $svc = app(JournalService::class);
    $svc->post(1, ['period' => '2026-07'], [
        ['coa_id' => $kas->id, 'debit' => 100],
        ['coa_id' => $pendapatan->id, 'credit' => 100],
    ], $user);

    $svc->closePeriod(1, '2026-07', $user);

    $svc->post(1, ['period' => '2026-07'], [
        ['coa_id' => $kas->id, 'debit' => 50],
        ['coa_id' => $pendapatan->id, 'credit' => 50],
    ], $user);
})->throws(RuntimeException::class);

test('koreksi via reversal: jurnal balik terbalik sisi, asli VOID (BR-016 append-only spirit)', function () {
    $user = User::factory()->create(['company_id' => 1]);
    $kas = coa('1101', 'ASSET', 'DEBIT');
    $pendapatan = coa('4101', 'REVENUE', 'CREDIT');

    $svc = app(JournalService::class);
    $journal = $svc->post(1, ['period' => '2026-08'], [
        ['coa_id' => $kas->id, 'debit' => 200],
        ['coa_id' => $pendapatan->id, 'credit' => 200],
    ], $user);

    $reversal = $svc->reverse($journal, $user, 'Salah input');

    expect($journal->fresh()->status)->toBe('VOID');
    expect($reversal->reverses_journal_id)->toBe($journal->id);
    // Sisi terbalik
    expect((float) $reversal->lines->firstWhere('coa_id', $kas->id)->credit)->toBe(200.0);
    expect((float) $reversal->lines->firstWhere('coa_id', $pendapatan->id)->debit)->toBe(200.0);
});

test('trial balance: agregasi per akun dari jurnal POSTED saja', function () {
    $user = User::factory()->create(['company_id' => 1]);
    $kas = coa('1101', 'ASSET', 'DEBIT');
    $pendapatan = coa('4101', 'REVENUE', 'CREDIT');

    $svc = app(JournalService::class);
    $j1 = $svc->post(1, ['period' => '2026-08'], [
        ['coa_id' => $kas->id, 'debit' => 500],
        ['coa_id' => $pendapatan->id, 'credit' => 500],
    ], $user);
    $svc->post(1, ['period' => '2026-08'], [
        ['coa_id' => $kas->id, 'debit' => 300],
        ['coa_id' => $pendapatan->id, 'credit' => 300],
    ], $user);
    $svc->reverse($j1, $user);   // j1 VOID → tidak dihitung; reversal ikut menetralkan

    $tb = collect($svc->trialBalance(1, '2026-08'))->keyBy('code');

    // Kas: 500 (j1) + 300 (j2) − 500 (reversal) = 300 debit; Pendapatan: 800 kredit − 500 = 300
    expect((float) $tb['1101']->total_debit - (float) $tb['1101']->total_credit)->toBe(300.0);
    expect((float) $tb['4101']->total_credit - (float) $tb['4101']->total_debit)->toBe(300.0);
});
