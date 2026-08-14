# PHASE 7 — QC, PACKING, SHIPMENT, SUBCONTRACTING (Implementation Plan)

> **Dasar:** ROADMAP v1.1 §Phase 7, MODULE_MAP §2.12–2.14, DATABASE_BLUEPRINT §3.12–3.14, PROCESS_FLOW PF-08/PF-09/PF-10/PF-11, BUSINESS_RULES BR-008/021/070/071/072/073/082/090/091, DEC-2026-08-13-01 s/d DEC-2026-08-14-01
> **Prasyarat:** Phase 6 ✅ (bundle selesai sewing/finishing, MO mendekati QC).

## Objective
Menjamin kualitas keluar (AQL per buyer), pengepakan per karton dengan rasio matrix, shipment sesuai toleransi buyer, dan siklus subcontracting (CMT) dengan stok in-transit terpantau.

## Current State
Bundle tersedia di stage FINISHING dengan hasil produksi harian. Belum ada inspeksi AQL, packing list, shipment, atau subcon order.

## Scope (dari ROADMAP Phase 7)
1. **QC** (PF-08/BR-070): inspeksi inline/endline/final; final inspection memakai **AQL dari customer config** (BR-008: ISO 2859-1 G-II, major 2.5 / minor 4.0 default); defect dari library (BR-072); verdict PASS/FAIL/REWORK; **FAIL → rework loop** (BR-073) lalu inspeksi ulang.
2. **Sampling AQL**: tabel lookup ISO 2859-1 (G-II) — lot size → sample size → Ac/Re per AQL; verdict otomatis dari jumlah defect vs Ac/Re.
3. **Packing** (PF-09): packing list per **karton** (carton barcode), isi karton = style×color×size×qty, **ratio check vs SO matrix** (BR-021/082), gross/net weight per karton.
4. **Shipment readiness** (BR-082): bundle/hasil produksi bisa dipacking hanya setelah QC PASS; FG masuk gudang FG via ITS `PRODUCTION_RECEIPT`; shipment keluar via ITS `SHIPMENT`.
5. **Shipment** (PF-10/BR-021): shipping instruction, actual qty vs SO vs **toleransi buyer** (BR-021: di luar batas → perlu approval/penyesuaian), booking forwarder, container info.
6. **Subcontracting CMT** (BR-090/091): subcon order ke supplier type SUBCON; ITS `SUBCON_OUT` (bahan keluar → `in_transit_subcon`), `SUBCON_IN` (hasil kembali); fee jasa per pcs dilacak untuk costing (BR-080).

## Business Rules yang Diimplementasikan
BR-008 (AQL config per buyer), BR-021 (toleransi shipment), BR-070 (tahapan QC), BR-071 (final = sampling AQL), BR-072 (defect library), BR-073 (rework loop), BR-082 (readiness = QC pass + packed), BR-090 (SUBCON_VIRTUAL warehouse), BR-091 (receipt subcon per MO+operation).

## Technical Design
- `Modules/Qc/Services/AqlSamplingService`: tabel ISO 2859-1 G-II (lot→kode→sample size; Ac/Re untuk AQL 1.0/1.5/2.5/4.0/6.5). `verdict(lotSize, defectsMajor, defectsMinor, aqlConfig)` → PASS/FAIL + detail sample.
- `Modules/Qc/Services/QcService`: create inspection (stage INLINE/ENDLINE/FINAL), record defects per bundle/MO, finalize verdict; FAIL → status MO/bundle REWORK → inspeksi ulang (cycle counter).
- `Modules/Packing/Services/PackingService`: create packing list; add cartons (barcode `PL-no-seq`); validate ratio vs SO lines (BR-021 tolerance); on finalize → ITS `PRODUCTION_RECEIPT` (WIP→FG).
- `Modules/Shipping/Services/ShipmentService`: SI dari packing list APPROVED; check qty vs SO ± tolerance (BR-021); post → ITS `SHIPMENT` (FG ↓); status SHIPPED → SO IN_PROGRESS/CLOSED bila semua terkirim.
- `Modules/Subcon/Services/SubconService`: create subcon order (supplier SUBCON) → `SUBCON_OUT`; receive → `SUBCON_IN` + fee tracking.

## Files To Change (batch)
1. ✅ Plan ini
2. Migrations QC (qc_inspections + lines, AQL verdict fields)
3. Migrations packing (packing_lists, cartons, carton_lines) + shipment (shipments, shipment_lines)
4. Migrations subcon (subcon_orders, subcon_order_lines, subcon_fees)
5. Models + AqlSamplingService
6. QcService + PackingService + ShipmentService + SubconService
7. Controllers + routes
8. Tests (AQL verdict, rework loop, ratio check, tolerance, subcon in/out, ITS FG) + README

## Database Changes
Tabel §3.12–3.14 blueprint.

## Testing (DoD)
- AQL: lot 1200, AQL 2.5 → sample 80, Ac 5/Re 6; 4 defect → PASS; 6 defect → FAIL (persis).
- Rework: FAIL → status REWORK → inspeksi ulang PASS → bisa packing (BR-073/082).
- Packing ratio: isi karton di luar rasio SO matrix ± toleransi → ditolak (BR-021/082).
- Shipment: qty di luar toleransi buyer → butuh flag approval; ITS SHIPMENT menurunkan FG (BR-021/006).
- Subcon: OUT menaikkan `in_transit_subcon`, IN menurunkannya (BR-090); saldo tidak pernah negatif.

## Risks
| Risiko | Mitigasi |
|---|---|
| AQL salah tabel → salah verdict | Lookup table eksplisit ISO 2859-1 + test angka persis |
| Shipment over/under tanpa kontrol | BR-021 check + approval flag; audit trail |
| Stok subcon hilang jejak | in_transit_subcon di saldo + SUBCON warehouse virtual (BR-090) |

## Open Decisions
Tidak ada yang menghalangi (OBD-013/015 sudah ber-answer default di blueprint).

## Next Step
Batch 2: migrasi QC. Setelah hijau: review → Phase 8 (Finance & Costing aktual + BEP).
