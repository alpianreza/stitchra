# Stitchra ERP — Status Proyek

> Diupdate: 2026-08-14. Status: **seluruh 9 fase implementasi code-complete** (menunggu test run & UAT oleh pemilik).

## Blueprint (LOCKED, `docs/`)
FASE_0 v1.0 · Business Specification v1.1 · Module Map v1.0 · Process Flow v1.0 · Business Rules v1.2 (26 LOCKED + 14 DEFAULT) · Database Blueprint v1.0 (±90 tabel, MySQL 8 + portabilitas PostgreSQL §7) · Roles & Permissions v1.1 (16 role) · Roadmap v1.1 · DECISION_LOG (DEC-2026-08-13-01 s/d DEC-2026-08-14-01) · BLUEPRINT_REVIEW FINAL v1.0

## Fase implementasi
| Fase | Modul | Status | Test |
|---|---|---|---|
| 1 Core Foundation | auth (Sanctum, lockout), RBAC, approval engine, numbering, audit, CI | ✅ | AuthTest, ApprovalEngineTest, NumberingServiceTest, PermissionMiddlewareTest, AuditLogTest |
| 2 Master Data | 22 entitas + import CSV (validasi per baris) | ✅ | MasterDataApiTest, MasterDataImportTest |
| 3 Sales/PD/Costing | SO matrix (BR-020), gate confirm (BR-023), BOM/Routing versioned (BR-030/033), cost sheet FOB (BR-100) | ✅ | BomVersioningTest, CostingAndSalesGateTest |
| 4 Inventory/Purchasing/Receiving | ITS atomic (BR-013), moving average (BR-005), quality hold (BR-004), GR roll-level (BR-052), 3-way match (BR-050) | ✅ | InventoryTransactionServiceTest, ReceivingFlowTest |
| 5 MRP/Planning/MO | netting (BR-043), read-only suggestion (BR-045), release=reservation (BR-060), shortage atomic (BR-040) | ✅ | MrpNettingTest, ProductionOrderReleaseTest |
| 6 Shop Floor | issue aktual/backflush (BR-041), leftover (BR-042), cutting+bundle (BR-061), scan IN/OUT (BR-062), WIP (BR-063) | ✅ | MaterialIssueTest, CuttingScanTest |
| 7 QC/Packing/Shipment/Subcon | AQL ISO 2859-1 (BR-008/071), rework loop (BR-073), ratio check (BR-082), toleransi (BR-021), subcon in/out (BR-090) | ✅ | AqlVerdictTest, PackingShipmentTest, SubconFlowTest |
| 8 Finance/Costing/BEP | jurnal balanced (BR-101), periode (BR-103), jurnal AUTO idempotent, AR/AP (BR-050/102), aging, actual costing + variance (BR-080/081), BEP (BR-104) | ✅ | JournalServiceTest, FinanceFlowTest, CostingBepTest |
| 9 Reporting/Dashboard | 8 report + KPI dashboard + export CSV | ✅ | ReportingDashboardTest |

## Menjalankan
```bash
docker compose -f infra/docker-compose.yml up -d --build
docker exec stitchra-api composer install
docker exec stitchra-api php artisan key:generate
docker exec stitchra-api php artisan migrate --seed
docker exec stitchra-api ./vendor/bin/pest        # seluruh test suite
docker exec stitchra-api php artisan horizon      # queue worker (job & notifikasi)
docker exec stitchra-api php artisan reverb:start # websocket
```
Login awal: `admin@stitchra.local` / `ChangeMe!123` — **segera ganti** (BR-111).

## Konfigurasi wajib sebelum produksi
1. **Account mappings** (`POST /api/finance/account-mappings`) untuk event GR_RECEIPT, MATERIAL_ISSUE, PRODUCTION_RECEIPT, SHIPMENT_COGS, AR_INVOICE, AR_PAYMENT, AP_PAYMENT, SUBCON_FEE — tanpa ini jurnal AUTO gagal (by design, BR-101).
2. COA perusahaan (import via master `chart-of-accounts`).
3. OH rate & line cost rate per periode berjalan (`overhead-rates`, `line-cost-rates`) — tanpa ini costing CM/OH = 0.
4. Approval flows per doc type (SO/PR/PO/BOM/ROUTING/COST/MO) sesuai approval matrix.
5. AQL config per buyer (`master customers` + `customer_aql_configs`).

## Open items (tidak menghalangi go-live bertahap)
- Rekomendasi belum diadopsi (tercatat DEC-2026-08-14-01): backup/DR offsite (**terkuat — server on-prem**), production calendar, supplier scorecard, rekonsiliasi kain per MO, laporan insentif operator.
- TD-03 perangkat shop floor: default keyboard-wedge scanner + browser.
- OBD-025 siklus tutup buku (default bulanan, cut-off H+3 kerja); OBD-026 pajak (PPN 11% standar di invoice).
- UI web: skeleton Phase 1 (login+dashboard) — screen bisnis menyusul per prioritas pemakaian.

## Struktur repo
`docs/` blueprint & plan fase · `apps/api` Laravel 13 modular (`app/Modules/*`, masing-masing ada README) · `apps/web` Next.js 16 · `infra/` Docker on-prem (MySQL 8.4, Redis 8, MinIO, Nginx) · CI GitHub Actions (`.github/workflows/ci.yml`).
