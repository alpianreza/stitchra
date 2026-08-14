# PHASE 9 — REPORTING & DASHBOARD (Implementation Plan)

> **Dasar:** ROADMAP v1.1 §Phase 9, MODULE_MAP §2.17, BUSINESS_RULES BR-080/081/104 (konsumsi laporan), DEC-2026-08-13-01 s/d DEC-2026-08-14-01
> **Prasyarat:** Phase 1–8 ✅ (seluruh data operasional + finance tersedia).

## Objective
Menutup loop visibility: 8 report inti lintas modul + dashboard KPI manajemen — semuanya read-only di atas data transaksi yang sudah dijaga integritasnya (ITS, approval, audit).

## Current State
Data lengkap dari Phase 1–8. Belum ada laporan teragregasi atau dashboard.

## Scope (dari ROADMAP Phase 9)
1. **8 report inti** (registry, parameterized, exportable CSV):
   1. `order_status` — SO lifecycle (open/confirmed/in progress/closed + nilai)
   2. `wip_summary` — WIP per MO per stage (dari bundles/scans, BR-063)
   3. `production_efficiency` — output vs target line (SAM earned vs jam hadir; sumber: scans + line capacity)
   4. `qc_summary` — pass rate per buyer/stage, Pareto defect dari library (BR-072)
   5. `stock_aging` — umur stok per material dari ledger (first receipt → hari ini)
   6. `consumption_variance` — BOM estimated vs actual per style (BR-031)
   7. `otd` — on-time delivery: ship_date vs ex_factory_date/delivery schedule
   8. `bep_position` — posisi volume aktual vs BEP per style (BR-104, Phase 8)
2. **Dashboard KPI** (1 endpoint agregat): open order value, MO aktif per status, output hari ini, WIP pcs, QC pass rate 7 hari, pending approval saya, pengiriman overdue, nilai stok (avg_cost × on_hand).
3. **Export CSV** per report (streaming; Excel via maatwebsite di iterasi berikut bila perlu).
4. **RBAC**: report view permission per domain (BR-110); data selalu company-scoped (BR-011).

## Business Rules yang Diimplementasikan
BR-011 (company scope), BR-031 (variance konsumsi), BR-063 (WIP), BR-072 (defect pareto), BR-104 (BEP position), BR-110 (permission per report).

## Technical Design
- `Modules/Reporting/Services/ReportService` — registry nama → closure query; semua query read-only, agregasi SQL (bukan PHP loop besar).
- `Modules/Reporting/Services/DashboardService` — KPI agregat 1 query-set.
- Export: CSV string → response download (text/csv) — sederhana, tanpa job.
- Tidak ada tabel baru (laporan = view atas data; audit tidak menulis untuk read).

## Files To Change
1. ✅ Plan ini
2. ReportService + DashboardService
3. Controllers + routes + provider
4. Tests (tiap report minimal 1 assertion angka; KPI dashboard) + README
5. PROJECT_STATUS.md (status akhir seluruh fase)

## Testing (DoD)
- Setiap report mengembalikan baris dengan angka yang diverifikasi dari fixture.
- Dashboard KPI cocok dengan data fixture (hitung manual).
- Permission: role tanpa akses → 403.

## Risks
| Risiko | Mitigasi |
|---|---|
| Query berat di data besar | Agregasi SQL + index yang sudah ada (ledger by item/wh/date, scans by line/date) |
| Angka "aneh" karena data master kotor | Laporan menampilkan apa adanya + validasi hulu sudah dijaga sejak Phase 2–8 |

## Open Decisions
- OBD-030 (kedalaman i18n UI) — default: UI Bahasa Indonesia dulu, label master tetap satu bahasa (sesuai rekomendasi tercatat).

## Next Step
Implementasi service + API → tests → PROJECT_STATUS.md final. Setelah hijau: fase review & UAT menyeluruh dengan pemilik.
