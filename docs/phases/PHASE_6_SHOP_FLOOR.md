# PHASE 6 — SHOP FLOOR EXECUTION (Implementation Plan)

> **Dasar:** ROADMAP v1.1 §Phase 6, MODULE_MAP §2.9–2.11, DATABASE_BLUEPRINT §3.9–3.11, PROCESS_FLOW PF-05/PF-06/PF-07, BUSINESS_RULES BR-041/042/060/061/062/063, DEC-2026-08-13-01 s/d DEC-2026-08-14-01
> **Prasyarat:** Phase 5 ✅ (MO RELEASED dengan reservasi material).

## Objective
Eksekusi produksi di lantai pabrik: issue material ke MO, cutting (marker + bundle tracking), sewing (scan per bundle per operasi), finishing — dengan WIP real-time dan konsumsi aktual.

## Current State
MO RELEASED dengan material ter-reservasi (BR-060). Belum ada pencatatan eksekusi.

## Scope (dari ROADMAP Phase 6)
1. **Material Issue** (BR-041): issue dari reservasi — fabric **aktual per roll** (qty terukur), trim boleh **backflush** (dari flag `is_backflush` di BOM); ITS `MATERIAL_ISSUE` (RM ↓, WIP ↑), reservasi `qty_issued` ↑.
2. **Cutting** (PF-05): cut order per MO, marker log (panjang marker, plies, rasio), **bundle** per cut line (bundle_no unik, qty, size), konsumsi aktual kain per roll (BR-041) → update `bom_lines.consumption_actual` (BR-031).
3. **Leftover return** (BR-042): sisa roll (`qty_remaining_meter`) kembali ke RM via ITS `PRODUCTION_RETURN` — bukan dihapus.
4. **Sewing** (PF-06): scan IN/OUT bundle per operasi (input scanner keyboard-wedge / manual), daily output per line, WIP per MO per stage, rework/defect inline (defect dari library, BR-072).
5. **Finishing** (PF-07): output finishing (washing/ironing/final check) → qty siap packing.
6. **MO status transitions**: RELEASED → CUTTING → SEWING → FINISHING → QC (BR-012) — otomatis dari event pertama stage terkait.

## Business Rules yang Diimplementasikan
BR-041 (issue aktual fabric / backflush trim), BR-042 (leftover return), BR-060 (issue mengurangi reservasi), BR-061 (bundle = unit tracking shop floor), BR-062 (scan IN/OUT = kehadiran fisik), BR-063 (WIP = bundle × stage), BR-072 (defect library).

## Technical Design
- `Modules/Production/Services/MaterialIssueService`:
  - `issue(mo, lines[], warehouse)`: validasi reservasi remaining ≥ qty → ITS MATERIAL_ISSUE → reservasi.qty_issued ↑ (status PARTIAL/FULLY_ISSUED) → alokasi.qty_issued ↑.
  - Backflush: `backflush(mo)`: hitung konsumsi standar trim dari BOM × qty_produced → issue otomatis tanpa input manual.
- `Modules/Cutting/Services/CuttingService`: create cut order (dari MO RELEASED), marker log, generate bundles (bundle_no = CUT-line-seq), record consumption per roll (`fabric_rolls.consume()`).
- `Modules/ShopFloor/Services/ScanService`: `scan(bundle_no, operation_id, direction IN/OUT, employee)` — validasi urutan operasi (bundle harus OUT dari operasi sebelumnya sebelum IN berikutnya), catat timestamp.
- Daily output: agregasi scan OUT per line per tanggal (query view, bukan tabel agregat — anti double-count).
- Status MO otomatis: event pertama `cut_orders` → CUTTING; scan sewing pertama → SEWING; dst.

## Files To Change (batch)
1. ✅ Plan ini
2. Migrations: material_issues(+lines), fabric_returns; cut_orders(+lines), bundles, marker_logs
3. Migrations: production_scans, rework_records
4. Models + MaterialIssueService (issue/backflush/return)
5. CuttingService + ScanService + status transitions
6. Controllers + routes
7. Tests (issue dari reservasi, backflush, leftover return, bundle flow, scan ordering) + README

## Database Changes
Tabel §3.9–3.11 blueprint.

## Testing (DoD)
- Issue ≤ reservasi; issue melebihi → ditolak; reservasi FULLY_ISSUED saat habis.
- Backflush trim: qty = BOM × qty_produced persis.
- Leftover: roll 100 m dipakai 80 → return 20 ke RM via PRODUCTION_RETURN; `qty_remaining_meter` 0 → CONSUMED.
- Scan: IN operasi 2 tanpa OUT operasi 1 → ditolak; daily output agregasi benar.
- Konsumsi aktual roll menurunkan `qty_remaining_meter`; BOM `consumption_actual` ter-update (BR-031).

## Risks
| Risiko | Mitigasi |
|---|---|
| Scan double / salah urutan | Validasi state bundle per operasi + idempotency by (bundle, operation, direction, timestamp window) |
| Konsumsi aktual tidak konsisten dengan stok | ITS satu pintu; roll consume dalam transaksi yang sama dengan ledger |

## Open Decisions
- TD-03 (perangkat shop floor): default **keyboard-wedge scanner + browser** (paling murah, offline-tolerant via queue lokal). Dikonfirmasi saat deployment.

## Next Step
Batch 2: migrasi material issue & cutting. Setelah hijau: review → Phase 7 (QC, Packing, Shipment, Subcon).
