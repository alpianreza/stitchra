# Stitchra ERP — Status Proyek

> Diupdate: 2026-08-14. Status: **9 fase backend code-complete + web UI operasional lengkap** (menunggu test run & UAT oleh pemilik).

## Blueprint (LOCKED, `docs/`)
FASE_0 v1.0 · Business Specification v1.1 · Module Map v1.0 · Process Flow v1.0 · Business Rules v1.2 (26 LOCKED + 14 DEFAULT) · Database Blueprint v1.0 (±90 tabel, MySQL 8 + portabilitas PostgreSQL §7) · Roles & Permissions v1.1 (16 role) · Roadmap v1.1 · DECISION_LOG (DEC-2026-08-13-01 s/d DEC-2026-08-14-01) · BLUEPRINT_REVIEW FINAL v1.0 · **PERMISSION_MAP.md** (endpoint → kode permission kanonik)

## Fase implementasi (backend, `apps/api` — modular `app/Modules/*`)
| Fase | Modul | Status | Test |
|---|---|---|---|
| 1 Core Foundation | auth (Sanctum, lockout), RBAC, approval engine, numbering, audit, CI | ✅ | AuthTest, ApprovalEngineTest, NumberingServiceTest, PermissionMiddlewareTest, AuditLogTest |
| 2 Master Data | 22 entitas + import CSV (validasi per baris) | ✅ | MasterDataApiTest, MasterDataImportTest |
| 3 Sales/PD/Costing | SO matrix (BR-020), gate confirm (BR-023), BOM/Routing versioned (BR-030/033), cost sheet FOB (BR-100) | ✅ | BomVersioningTest, CostingAndSalesGateTest |
| 4 Inventory/Purchasing/Receiving | ITS atomic (BR-013), moving average (BR-005), quality hold (BR-004), GR roll-level (BR-052), 3-way match (BR-050) | ✅ | InventoryTransactionServiceTest, ReceivingFlowTest, InventoryOpsTest |
| 5 MRP/Planning/MO | netting (BR-043), read-only suggestion (BR-045), release=reservation (BR-060), shortage atomic (BR-040) | ✅ | MrpNettingTest, ProductionOrderReleaseTest |
| 6 Shop Floor | issue aktual/backflush (BR-041), leftover (BR-042), cutting+bundle (BR-061), scan IN/OUT (BR-062), WIP (BR-063) | ✅ | MaterialIssueTest, CuttingScanTest |
| 7 QC/Packing/Shipment/Subcon | AQL ISO 2859-1 (BR-008/071), rework loop (BR-073), ratio check (BR-082), toleransi (BR-021), subcon in/out (BR-090) | ✅ | AqlVerdictTest, PackingShipmentTest, SubconFlowTest |
| 8 Finance/Costing/BEP | jurnal balanced (BR-101), periode (BR-103), jurnal AUTO idempotent, AR/AP (BR-050/102), aging, actual costing + variance (BR-080/081), BEP (BR-104) | ✅ | JournalServiceTest, FinanceFlowTest, CostingBepTest |
| 9 Reporting/Dashboard | 8 report + KPI dashboard + export CSV | ✅ | ReportingDashboardTest |

## Web UI (`apps/web` — Next.js 16)
Login · App shell (nav + auth guard) · **Dasbor KPI** (tersambung `/api/dashboard/kpis`) · **Approval** inbox (approve/reject/revisi) + **Approval Flow** setup (admin) · **Sales Order** list + buat (matrix editor BR-020) · **BOM** & **Routing** editor versioned + submit approval · **Cost Sheet** (compute FOB → set harga → submit) · **MRP** planner (run → shortage → konversi PR) · **PR** list · **PO** list + buat + submit · **Manufacturing Order** list + detail (release/unrelease + **issue material** dengan pemilih roll BR-041) · **Stasiun Scan** (keyboard-wedge, BR-062) · **Goods Receipt** (input per roll BR-052) + daftar · **Inward QC/FQC** (per roll/line PASS-FAIL → release hold BR-004) · **Inquiry Stok** (BR-006) · **Operasi Stok** (transfer/adjustment/opname) · **Inspeksi QC** (AQL otomatis) · **Packing List** (karton matrix + finalize BR-082) · **Shipment** (toleransi BR-021 + approve + ship) · **Subcon** (kirim/terima) · **Jurnal** manual (indikator balance BR-101) · **Costing Aktual** per MO (variance BR-081) · **BEP** kalkulator (BR-104) · **Laporan** (8 report + export CSV) · Master data generik (customer, supplier, material, style, warehouse, karyawan).

## Perbaikan penting pasca-implementasi
1. **RBAC alignment**: kode permission seluruh controller diselaraskan ke blueprint LOCKED (`RbacSeeder`) — mismatch awal (mis. `production.order.*` vs `production.mo.*`) akan meng-403-kan user non-super-admin. Kanonik: `docs/PERMISSION_MAP.md`.
2. `ApprovalRequest::flow()` relation ditambahkan (bug laten di approve).
3. Prefix numbering lengkap di seeder (JE/INV/PAY/QC/PL/CUT/TRF/dst); cut order `CUT` (bukan `OUT`).
4. Test fixtures dipusatkan di `tests/Helpers/ErpFixtures.php` (anti redeclare).
5. Inventory ops (transfer/adjustment/opname) terkait approval engine doc_type ADJ/OPN (BR-017).

## Menjalankan
```bash
docker compose -f infra/docker-compose.yml up -d --build
docker exec stitchra-api composer install
docker exec stitchra-api php artisan key:generate
docker exec stitchra-api php artisan migrate --seed
docker exec stitchra-api ./vendor/bin/pest        # seluruh test suite
docker exec stitchra-api php artisan horizon      # queue worker
docker exec stitchra-api php artisan reverb:start # websocket
```
Login awal: `admin@stitchra.local` / `ChangeMe!123` — **segera ganti** (BR-111).

## Konfigurasi wajib sebelum produksi
1. **Approval flows** per doc type — sekarang bisa dari UI: menu Admin → Approval Flow (SO/PR/PO/BOM/ROUTING/COST/MO/ADJ/OPN).
2. **Account mappings** (`/api/finance/account-mappings` atau seeder) untuk jurnal AUTO (GR_RECEIPT, MATERIAL_ISSUE, PRODUCTION_RECEIPT, SHIPMENT_COGS, AR_INVOICE, AR_PAYMENT, AP_PAYMENT, SUBCON_FEE).
3. COA perusahaan (import master `chart-of-accounts`).
4. OH rate & line cost rate per periode (`overhead-rates`, `line-cost-rates`).
5. AQL config per buyer (customer + `customer_aql_configs`).

## Open items (tidak menghalangi go-live bertahap)
- Rekomendasi belum diadopsi (DEC-2026-08-14-01): backup/DR offsite (**terkuat — server on-prem**), production calendar, supplier scorecard, rekonsiliasi kain per MO, laporan insentif operator.
- TD-03 perangkat shop floor: default keyboard-wedge scanner + browser.
- OBD-025 siklus tutup buku (default bulanan); OBD-026 pajak (PPN 11% standar).
- UI belum menyembunyikan menu per role (otorisasi tetap enforced server-side; menu non-authorized akan 403 dengan pesan jelas).

## Struktur repo
`docs/` blueprint & plan fase & status · `apps/api` Laravel 13 modular (masing-masing modul ada README) · `apps/web` Next.js 16 (Tailwind v4) · `infra/` Docker on-prem (MySQL 8.4, Redis 8, MinIO, Nginx) · CI GitHub Actions.
