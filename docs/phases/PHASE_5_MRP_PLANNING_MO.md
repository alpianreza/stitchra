# PHASE 5 — MRP, PLANNING, MANUFACTURING ORDER (Implementation Plan)

> **Dasar:** ROADMAP v1.1 §Phase 5, MODULE_MAP §2.5, DATABASE_BLUEPRINT §3.5, PROCESS_FLOW PF-04, BUSINESS_RULES BR-040/041/042/043/045/060/080/081, DEC-2026-08-13-01 s/d DEC-2026-08-14-01
> **Prasyarat:** Phase 3 ✅ (SO CONFIRMED, BOM/Routing APPROVED), Phase 4 ✅ (saldo, reservasi, PR).

## Objective
MRP run dari SO CONFIRMED (BOM explode → gross → net → shortage → saran PR), pembuatan MO per SO line, dan MO release dengan hard reservation — fondasi shop floor (Phase 6).

## Current State
SO CONFIRMED + BOM APPROVED + stok tersedia. Belum ada kalkulasi kebutuhan material dan MO.

## Scope (dari ROADMAP Phase 5)
1. **MRP Run** (BR-043): dari SO CONFIRMED → BOM explode (qty × grossPerPcs) → gross requirement per material → netting: `net = gross + safety_stock − available − on_order` → shortage list → **suggested PR lines** (read-only suggestion, BR-045: MRP TIDAK auto-PO).
2. **MRP versioning**: setiap run tersimpan (run ke-N, parameter, hasil) — planner membandingkan run.
3. **MO (production_orders):** generate dari SO lines (qty per style×color×size diagregasi ke style), status lifecycle PLANNED → RELEASED → CUTTING → SEWING → FINISHING → QC → PACKED → CLOSED (subset BR-012).
4. **MO release = hard reservation** (BR-060): per BOM line × qty_planned → `stock_reservations` + saldo `reserved` ↑; kekurangan → error berisi shortage (planner manual per BR-040, tidak auto-adjust).
5. **MR → PR conversion:** planner memilih shortage lines → PR `source = MRP` (BR-120 trace via `pr_lines.mrp_requirement_id`).
6. **Kapasitas dasar:** loading per line = Σ(SAM × qty MO) vs kapasitas line (capacity_std) — read-only report, penjadwalan detail via Gantt (OBD-010, UI Phase 6+).
7. **FK closure:** `stock_reservations.mo_id` → `production_orders` (kolom disiapkan Phase 4, FK ditambahkan sekarang).

## Business Rules yang Diimplementasikan
BR-040 (shortage manual), BR-041 (fondasi issue mode di MO), BR-042 (fondasi leftover), BR-043 (netting + safety stock + time fence), BR-045 (MRP read-only → suggest PR), BR-060 (release = reservation), BR-080/081 (fondasi actual cost per MO), BR-010/015 (nomor MO + approval).

## Technical Design
- `Modules/Planning/Services/MrpService`:
  - `run(companyId, soIds[], params): MrpRun` — explode BOM APPROVED per style, agregasi per material, netting, simpan `mrp_requirements`.
  - Available dari `stock_balances` (on_hand − reserved − quality_hold); on-order dari PO APPROVED/PARTIAL_RECEIVED (`qty − received_qty`).
  - BR-043: safety stock masuk netting: `net = max(0, gross + safety_stock − available − on_order)`.
  - `generatePrSuggestions(runId)` → draft PR lines (TIDAK auto-create PR — BR-045).
- `Modules/Production/Services/ProductionOrderService`:
  - `createFromSalesOrder(soId, splits[]): MO[]` — MO per style (+optional colorway/size breakdown).
  - `release(mo)` → reservation per BOM line (lock saldo, validasi available; shortage → RuntimeException dengan daftar kurang).
  - `unrelease(mo)` → lepas reservasi (status PLANNED).
- Locking: reservation lock saldo `lockForUpdate` (konsisten ITS).
- Time fence/frozen window: `params` di mrp_runs (dari settings `planning.time_fence_days`).

## Files To Change (batch)
1. ✅ Plan ini
2. Migrations: mrp_runs, mrp_requirements, production_orders, mo_material_allocations + FK reservations
3. Models Planning & Production
4. MrpService (explode/netting/suggest) + ProductionOrderService (release/reserve)
5. Controllers + routes
6. Tests (netting angka persis, reservation berhasil & gagal-shortage, PR trace BR-120) + README

## Database Changes
Tabel §3.5 blueprint + FK tambahan `stock_reservations.mo_id`.

## Testing (DoD)
- Netting: fixture stok 100, on-order 50, safety 10, gross 500 → net = 360 (persis).
- MO release: saldo cukup → reserved ↑ & status RELEASED; saldo kurang → error shortage + tidak ada reservasi parsial (atomic).
- MRP tidak membuat PO/PR otomatis (BR-045): run hanya menghasilkan `mrp_requirements`; PR dibuat eksplisit planner dengan `source=MRP` + trace ke requirement.
- BR-120: PR line menyimpan `mrp_requirement_id`.

## Risks
| Risiko | Mitigasi |
|---|---|
| BOM master salah → MRP salah (risiko #3 FASE 0) | Gate BR-023 sudah aktif di Phase 3; MRP hanya baca BOM APPROVED |
| Reservasi race antar planner | lockForUpdate saldo; test gagal-shortage |
| Over-allocation antar MO | Available selalu dihitung bersih; reservasi menolak bila kurang |

## Open Decisions
- OBD-010 (detail scheduling shop floor) — paling lambat akhir fase; default rekomendasi: Gantt drag-drop line×tanggal, conflict warning, dispatch list harian (untuk UI Phase 6+).

## Next Step
Batch 2: migrasi. Setelah hijau: review → Phase 6 (Shop Floor: cutting, sewing, finishing — eksekusi produksi).
