# PHASE 2 — MASTER DATA (Implementation Plan)

> **Dasar:** ROADMAP v1.1 §Phase 2, MODULE_MAP §2.2, DATABASE_BLUEPRINT §3.2, BUSINESS_RULES (BR-002/003/004/008/021/023/030/033/053/072, BR-110/111), DEC-2026-08-13-01 s/d DEC-2026-08-14-01
> **Prasyarat:** Phase 1 selesai (auth, RBAC, approval, numbering, audit aktif).

## Objective
Menyediakan seluruh master data yang dipakai modul bisnis: customer, supplier, employee, style/product, material, UOM+konversi, warehouse, machine/line/operation (SMV), defect library, COA, currency, rates — dengan validasi, versioning-ready, dan import CSV.

## Current State
Phase 1: Core aktif (users, roles, approval, numbering, audit). Belum ada master data bisnis.

## Scope (dari ROADMAP Phase 2)
1. Customer (+AQL config per buyer BR-008, toleransi shipment BR-021)
2. Supplier (tipe: FABRIC/TRIM/PACKAGING/SUBCON — subcon untuk Phase 7)
3. Employee (operator: NIK, section, line, skill)
4. Product: style, color, colorway, shade group, size & size range
5. UOM + konversi per material (BR-002); Material: fabric (GSM/lebar/shrinkage), trim, packaging
6. Warehouse + location (RM/WIP/FG/Trim/SUBCON virtual — BR-090)
7. Machine, line, operation + SMV versioned (BR-033), defect library (BR-072)
8. Finance master: COA (BR-101), currency + exchange rate (BR-102), OH rate & line cost rate (BR-009)
9. Approval matrix (BR-015) + import CSV master (Laravel Excel)

## Business Rules yang Diimplementasikan
BR-002 (dual UOM konversi), BR-003 (roll vs lot level), BR-004 (quality hold default), BR-008 (AQL config), BR-021 (toleransi), BR-023 (style lifecycle), BR-030 (BOM/routing versioning — fondasi), BR-033 (SMV versioned), BR-053 (shade group), BR-072 (defect library), BR-090 (warehouse SUBCON), BR-101 (COA), BR-102 (currency).

## Technical Design
- Semua tabel: kolom standar §1.1 blueprint (company_id, audit columns, soft delete master).
- Kode unik per company (uq_company_code) — dicek service + DB.
- Soft delete terkunci bila master dipakai transaksi (validasi service).
- Import CSV per master via `core.integration` (integration_jobs) + validasi baris.
- Endpoint CRUD per entitas dengan middleware `permission:master.<entity>.<action>`.

## Files To Change (batch)
1. ✅ Plan ini
2. Migrations: customers, customer_aql_configs, suppliers, employees
3. Migrations: styles, colors, colorways, shade_groups, sizes, size_ranges, size_range_lines
4. Migrations: uoms, uom_conversions, materials, material_uom_conversions
5. Migrations: warehouses, locations, machines, lines, operations, operation_versions, defect_library
6. Migrations: chart_of_accounts, currencies, exchange_rates, overhead_rates, line_cost_rates
7. Models + relasi
8. Controllers + routes (permission middleware)
9. Import CSV + validasi
10. Tests + README modul

## Database Changes
Seluruh tabel §3.2 Database Blueprint. Tidak ada perubahan tabel Core.

## Testing (DoD)
- CRUD per master + validasi unik per company.
- Soft delete ditolak bila master dipakai (mis. material dipakai BOM — disimulasikan).
- Konversi UOM: kg↔meter dengan GSM×lebar menghasilkan rate benar (BR-002).
- Permission: role tanpa `master.customer.create` → 403.
- Import CSV: baris invalid terlapor, baris valid masuk.

## Risks
| Risiko | Mitigasi |
|---|---|
| Master kotor → MRP/costing salah (risiko #3 FASE 0) | Validasi ketat + template impor + contoh data |
| Variant axis salah model | Style ≠ SKU; SKU = style×colorway×size (BR-020) ditegakkan di model |

## Open Decisions
Tidak ada yang menghalangi. (Data profil bisnis — jumlah warehouse/line/operator — membantu saat seeding produksi, bukan desain.)

## Next Step
Migrations batch 1 → models → API → import → tests. Setelah hijau: review → Phase 3 (Sales/PD/Costing estimasi).
