# PHASE 8 — FINANCE, COSTING AKTUAL & BEP (Implementation Plan)

> **Dasar:** ROADMAP v1.1 §Phase 8, MODULE_MAP §2.15–2.16, DATABASE_BLUEPRINT §3.15, PROCESS_FLOW PF-08, BUSINESS_RULES BR-009/021/050/080/081/100/101/102/103/104, DEC-2026-08-14-01 (BEP milik Accounting)
> **Prasyarat:** Phase 4–7 ✅ (ITS ledger berbiaya, subcon fees, shipment, COA master, cost sheet standard).

## Objective
1. **Costing aktual per MO** (BR-080/081): material aktual (dari ledger issue), labor (output × SAM × cost/min), OH (SAM × OH rate), subcon fee → actual vs standard (variance).
2. **GL lengkap** (BR-101): jurnal terbalance (debit=kredit) dari transaksi operasional via mapping akun + jurnal manual; periode tutup (BR-103); trial balance, neraca, laba rugi.
3. **AR/AP:** invoice penjualan dari shipment, pembayaran customer & supplier, aging report.
4. **BEP** (BR-104): `BEP qty = Fixed Cost ÷ (harga jual − variable cost per unit)` — factory-wide/bulan + per style; dihitung dari data (tanpa tabel baru).

## Current State
COA, currency, exchange rate, OH rate, line cost rate aktif. Transaksi operasional semua lewat ITS (berbiaya). Belum ada jurnal, AR/AP, costing aktual, BEP.

## Scope (dari ROADMAP Phase 8)
1. `journals` + `journal_lines` (CHECK debit XOR credit; service menolak tidak balance — BR-101); `gl_periods` (OPEN/CLOSED — BR-103); `account_mappings` (event → debit/credit COA).
2. Auto-journal dari event: GR (Persediaan RM ↑ / Utang AP ↑), material issue (WIP ↑ / RM ↓), shipment (HPP ↑ / FG ↓ + Piutang AR ↑ / Pendapatan ↑), invoice & payment AR/AP (Kas/Bank). Posting via `JournalService.post()` — satu-satunya pintu GL.
3. AR: `ar_invoices` (dari shipment SHIPPED, harga SO, kurs BR-102) + `ar_payments` (alokasi parsial); AP: pembayaran terhadap `supplier_invoices` (3-way matched).
4. Aging AR/AP (bucket 0–30/31–60/61–90/>90 dari due_date).
5. Actual costing per MO (BR-080/081) + variance vs cost sheet APPROVED (BR-100).
6. BEP (BR-104): input fixed cost per periode (dari GL akun EXPENSE fixed / input manual), harga & variable cost dari cost sheet / shipment aktual.
7. Kurs: transaksi multi-currency menyimpan rate transaksi (BR-102); jurnal dalam base currency.

## Business Rules yang Diimplementasikan
BR-009 (OH per menit SAM), BR-050 (hanya invoice MATCHED yang bisa dibayar), BR-080/081 (actual cost & variance), BR-100 (standard cost baseline), BR-101 (jurnal balance), BR-102 (kurs), BR-103 (periode tutup — tidak ada posting ke periode CLOSED), BR-104 (BEP, Accounting).

## Technical Design
- `Modules/Finance/Services/JournalService`: `post(companyId, lines[], meta)` — validasi balance (Σdebit = Σcredit, toleransi 0.0001), periode OPEN, atomic; reversal via jurnal balik (bukan edit — append-only spirit BR-016).
- `GlPostingService`: event → mapping → journal AUTO (idempotent per source_document).
- `ActualCostingService.computeForMo(mo)`: 
  - material aktual = Σ ledger MATERIAL_ISSUE terkait MO × unit_cost (moving average saat issue)
  - labor = output (scan OUT) × total SAM × line cost rate periode
  - OH = SAM × output × OH rate (BR-009)
  - subcon = Σ subcon_fees MO
  - variance per komponen vs standard cost sheet APPROVED
- `BepService`: factory-wide per bulan (fixed cost input/GL, rata-rata harga & variable cost dari cost sheets/shipment) + per style (BR-104).

## Files To Change (batch)
1. ✅ Plan ini
2. Migrations: journals, journal_lines, gl_periods, account_mappings
3. Migrations: ar_invoices, ar_invoice_lines, ar_payments, ap_payments
4. Models + JournalService (+tests balance/period)
5. GlPostingService + hooks (dipanggil manual/service event — tidak mengubah modul operasional: dibaca dari data)
6. ActualCostingService + BepService
7. AR/AP services + aging
8. Controllers + routes
9. Tests + README

## Database Changes
Tabel §3.15 blueprint.

## Testing (DoD)
- Jurnal tidak balance → ditolak; posting ke periode CLOSED → ditolak (BR-101/103).
- Auto-journal GR: Persediaan ↑ = nilai GR, Utang ↑ sama; idempotent (2× post = 1 jurnal).
- Actual costing: fixture issue 100 × avg 10 = material 1000; labor/OH dari SAM × rate persis; variance = actual − standard per komponen.
- BEP: fixed 100jt, harga 50rb, variable 30rb → BEP 5000 pcs (persis).
- AR aging: invoice jatuh tempo lewat 45 hari → bucket 31–60.

## Risks
| Risiko | Mitigasi |
|---|---|
| Mapping akun salah → GL salah | Mapping wajib per event; jurnal AUTO gagal jelas bila mapping belum ada (tidak mengarang) |
| Kurs berbeda antar dokumen | Rate tersimpan per dokumen (BR-102) |

## Open Decisions
- OBD-025 (siklus tutup buku: default bulanan, cut-off hari kerja ke-3) — konfirmasi saat implementasi periode.
- OBD-026 (metode pajak) — default: PPN standar 11% di invoice; PPh 23 jasa subcon manual.

## Next Step
Batch 2: migrasi GL. Setelah hijau: review → Phase 9 (Reporting & Dashboard).
