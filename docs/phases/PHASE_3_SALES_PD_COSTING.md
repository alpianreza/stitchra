# PHASE 3 — SALES, PRODUCT DEVELOPMENT, COSTING ESTIMASI (Implementation Plan)

> **Dasar:** ROADMAP v1.1 §Phase 3, MODULE_MAP §2.3–2.4, DATABASE_BLUEPRINT §3.3–3.4, PROCESS_FLOW PF-01/PF-02, BUSINESS_RULES BR-020/022/023/030/031/032/033/100, DEC-2026-08-13-01 s/d DEC-2026-08-14-01
> **Prasyarat:** Phase 1 (Core) ✅, Phase 2 (Master Data) ✅.

## Objective
Order masuk (SO + matrix style×color×size), pengembangan produk (spec, sample, BOM, routing versioned), dan costing estimasi (FOB) → standard cost — fondasi untuk MRP (Phase 5).

## Current State
Master data aktif (style, material, customer, operation+SMV, OH rate, line cost rate). Belum ada dokumen transaksi.

## Scope (dari ROADMAP Phase 3)
1. Sales Order: header + matrix lines (BR-020), amendment (BR-022), delivery schedule; nomor SO via numbering (BR-010); approval via engine (BR-015).
2. BR-023: SO tidak bisa CONFIRMED tanpa BOM + Routing versi APPROVED untuk semua style di order.
3. PD: style spec, measurement chart, tech pack (upload S3), sample cycle (PROTO/FIT/PP/TOP + approval buyer).
4. BOM versioned (BR-030/031/032): lines per material/colorway, qty/pcs, wastage%, shrinkage%, consumption_estimated, is_backflush; perubahan pasca-approval = versi baru.
5. Routing versioned (BR-033): urutan operasi + SMV → total SAM.
6. Pre-production Cost Sheet (BR-100): fabric + trim + CM (SAM × cost/min) + OH (SAM × OH rate) → FOB; approved → standard cost.

## Business Rules yang Diimplementasikan
BR-020 (matrix line), BR-022 (amendment lock setelah cutting — hook), BR-023 (SO confirm gate), BR-030 (versioning), BR-031 (estimated vs actual consumption), BR-032 (kolom BOM line), BR-033 (SMV), BR-100 (standard cost), BR-010/015 (numbering+approval).

## Technical Design
- `Modules/Sales`: SalesOrderService — create (matrix lines), submit → approval, confirm (validasi BR-023), amendment (delta + status).
- `Modules/ProductDev`: BomService — `createVersion()`, `approve(version)` (tidak edit in-place; versi lama → OBSOLETE), `activeVersion(styleId)`. RoutingService serupa. CostingService — hitung FOB dari BOM approved + routing approved + rates periode berjalan.
- Numbering: SO, SMPL, COST prefixes (doc_numbering_configs).
- Semua submit → `ApprovalEngine::submit()` (BR-015).

## Files To Change (batch)
1. ✅ Plan ini
2. Migrations Sales (sales_orders, sales_order_lines, order_amendments, delivery_schedules, inquiries)
3. Migrations PD (style_specs, measurement_charts/lines, tech_packs, samples, sample_approvals)
4. Migrations BOM + Routing + CostSheet
5. Models Sales & PD
6. Models BOM/Routing/CostSheet + services (versioning, costing)
7. Controllers + routes
8. Tests + README modul

## Database Changes
Tabel §3.3 & §3.4 blueprint.

## Testing (DoD)
- BR-023: SO confirm tanpa BOM approved → ditolak 422; dengan BOM+routing approved → CONFIRMED.
- BR-030: edit BOM approved ditolak; createVersion membuat versi baru, versi lama OBSOLETE.
- BR-100: cost sheet FOB = fabric+trim+CM+OH terhitung benar dari fixture SAM & rates.
- Numbering: SO-YYYY-000001 dst. unik; approval flow jalan.

## Risks
| Risiko | Mitigasi |
|---|---|
| BOM/routing master salah → MRP salah | Gate BR-023 + versioning + approval wajib |
| Formula costing salah | Test dengan fixture angka pasti (SMV × rate) |

## Open Decisions
- OBD-012 (pemilik SAM) — paling lambat akhir fase; default: IE, versioned (BR-033).

## Next Step
Migrations → models → services → API → tests. Setelah hijau: review → Phase 4 (Inventory/Purchasing/Receiving — fase paling kritis).
