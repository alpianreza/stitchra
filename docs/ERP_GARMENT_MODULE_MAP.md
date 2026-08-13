# ERP GARMENT — MODULE MAP (FASE 2)

> **Status:** ✅ LOCKED v1.0 — disetujui pemilik 13 Agustus 2026
> **Tanggal:** 13 Agustus 2026
> **Dasar:** FASE 0 v1.0 (LOCKED), FASE 1 Business Specification v1.0, DECISION_LOG DEC-2026-08-13-01 s/d 03
> **Tujuan:** Memetakan domain → modul → tanggung jawab → kepemilikan data → dependensi, sebagai dasar arsitektur & database blueprint (FASE 3).

---

## 1. PRINSIP ARSITEKTUR MODUL

1. **Bounded context per domain** — setiap modul memiliki datanya sendiri; modul lain membaca via service/query, bukan join sembarangan.
2. **Single writer per data** — satu modul boleh menulis satu aggregate. Contoh: hanya `Inventory` yang menulis `stock_ledger` (BR-013).
3. **Core tidak bergantung pada modul bisnis** — arah dependensi: bisnis → core, tidak terbalik.
4. **Komunikasi lintas modul** lewat service interface + domain events (untuk decoupling MRP, costing, audit).
5. **Semua business rule terkonfigurasi di master data** (approval matrix, AQL config, toleransi) — bukan di kode modul.
6. Modul disusun mengikuti urutan implementasi (FASE 22 master prompt) agar tiap fase deliverable berdiri sendiri.

---

## 2. PETA MODUL & TANGGUNG JAWAB

### 2.1 CORE (fondasi, tidak ada business logic garment)

| Modul | Tanggung jawab | Data yang dimiliki | Dependensi |
|---|---|---|---|
| `core.organization` | Company, factory/branch, mata uang dasar | `companies`, `factories` | — |
| `core.user` | Akun, kredensial, profil, session | `users`, `user_credentials` | organization |
| `core.rbac` | Role, permission granular, assignment | `roles`, `permissions`, `role_permissions`, `user_roles` | user |
| `core.approval` | Engine approval terpusat: sequential/parallel, reject, revision, delegation, history | `approval_flows` (definisi), `approval_requests`, `approval_steps` (instance) | user, rbac |
| `core.numbering` | Nomor dokumen `PREFIX-YYYY-NNNNNN`, counter concurrency-safe (BR-010) | `doc_numbering_configs`, `doc_number_counters` | organization |
| `core.audit` | Audit log append-only: who/what/when/before/after/IP/device | `audit_logs` | (dipanggil semua modul) |
| `core.settings` | System settings, feature flags, konfigurasi formula | `settings` | organization |
| `core.notification` | Notifikasi in-app/email (approval pending, shortage, dst.) | `notifications` | user |
| `core.integration` | Import/export (CSV/Excel), barcode payload | `integration_jobs` | — |

**Catatan:** approval, numbering, audit adalah *shared service* — dipanggil modul bisnis, tidak pernah di-bypass.

### 2.2 MASTER DATA

| Modul | Tanggung jawab | Data yang dimiliki | Dependensi |
|---|---|---|---|
| `master.customer` | Buyer, brand, term, toleransi shipment (OBD-019), AQL config per buyer (BR-008) | `customers`, `customer_aql_configs` | core |
| `master.supplier` | Supplier fabric/trim/packaging/**subcon** (tipe menentukan perilaku job-work) | `suppliers` | core |
| `master.employee` | Karyawan/operator: NIK, section, line, skill | `employees` | organization, production.line |
| `master.product` | Style (style master, buyer ref, season, kategori woven/knit), **variant axis color×size**, colorway, shade group, size & size range | `styles`, `colors`, `colorways`, `shade_groups`, `sizes`, `size_ranges` | core |
| `master.material` | Material fabric (GSM, lebar, shrinkage, UOM beli/pakai), trim, packaging | `materials`, `material_uom_conversions` | uom |
| `master.uom` | UOM + konversi per material (BR-002) | `uoms`, `uom_conversions` | core |
| `master.warehouse` | Warehouse (RM/WIP/FG/Trim/SUBCON-virtual), location/bin | `warehouses`, `locations` | organization |
| `master.production` | Machine, line/section, operation + SMV/SAM (versioned), defect library | `machines`, `lines`, `operations`, `operation_versions`, `defect_library` | core |
| `master.finance` | COA, currency & rate, OH rate per periode, cost-per-minute per line | `chart_of_accounts`, `currencies`, `exchange_rates`, `overhead_rates`, `line_cost_rates` | organization |

### 2.3 SALES / MERCHANDISING

| Modul | Tanggung jawab | Data yang dimiliki | Dependensi |
|---|---|---|---|
| `sales.order` | Buyer PO, SO + matrix detail (style×color×size), amendment, delivery schedule | `sales_orders`, `sales_order_lines`, `order_amendments`, `delivery_schedules` | master.customer, master.product, core.approval, core.numbering |
| `sales.inquiry` (ringan) | Tracking enquiry/quotation awal ke buyer | `inquiries` | master.customer |

### 2.4 PRODUCT DEVELOPMENT

| Modul | Tanggung jawab | Data yang dimiliki | Dependensi |
|---|---|---|---|
| `pd.style` | Lifecycle style, tech pack, size spec/measurement chart | `style_specs`, `measurement_charts`, `tech_packs` | master.product |
| `pd.sample` | Sample stages (proto/fit/PP/TOP) + status approval buyer | `samples`, `sample_approvals` | pd.style |
| `pd.bom` | BOM versioned: fabric+trim per colorway, **estimated vs actual consumption**, wastage/shrinkage allowance (BR-014) | `boms`, `bom_versions`, `bom_lines` | master.material, pd.style |
| `pd.routing` | Routing versioned: sequence operasi + SMV per style | `routings`, `routing_versions`, `routing_operations` | master.production, pd.style |
| `pd.costing` | Pre-production cost sheet (estimated): fabric+trim+CM(SMV×cost/min)+OH → FOB | `cost_sheets`, `cost_sheet_lines` | pd.bom, pd.routing, master.finance |

### 2.5 PLANNING (PPIC)

| Modul | Tanggung jawab | Data yang dimiliki | Dependensi |
|---|---|---|---|
| `planning.mrp` | MRP run: gross (BOM×qty) − on-hand − reserved − open PO + safety stock = **nett**, dengan **requirement trace** ("kenapa butuh N?") | `mrp_runs`, `mrp_requirements`, `mrp_trace_lines` | sales.order, pd.bom, inventory (read), purchasing (read) |
| `planning.production` | Production plan per line/periode, line loading vs kapasitas | `production_plans`, `line_loading` | master.production, sales.order |
| `planning.cutplan` | Cut plan per MO: marker plan, size ratio per lay | `cut_plans`, `cut_plan_lays` | production.mo, pd.bom |

### 2.6 PURCHASING

| Modul | Tanggung jawab | Data yang dimiliki | Dependensi |
|---|---|---|---|
| `purchasing.pr` | PR (otomatis dari MRP / manual), approval | `purchase_requests`, `pr_lines` | planning.mrp, core.approval |
| `purchasing.rfq` | RFQ, supplier quotation, comparison | `rfqs`, `quotations`, `quotation_lines` | master.supplier |
| `purchasing.po` | PO + lines, approval berjenjang (limit nilai dari matrix), tracking received qty | `purchase_orders`, `po_lines` | purchasing.rfq, core.approval, core.numbering |
| `purchasing.invoice` | Supplier invoice + **3-way match** (PO–GR–invoice) | `supplier_invoices`, `supplier_invoice_lines` | purchasing.po, receiving.gr (read) |

### 2.7 RECEIVING & INWARD QC

| Modul | Tanggung jawab | Data yang dimiliki | Dependensi |
|---|---|---|---|
| `receiving.gr` | GR + lines; **roll-level untuk fabric** (BR-003/052), status awal `QUALITY_HOLD` (BR-004) | `goods_receipts`, `gr_lines`, `fabric_rolls` | purchasing.po, inventory (via ITS) |
| `receiving.inspection` | Fabric/trim inspection: 4-point, shrinkage, GSM, shade; PASS → release hold, FAIL → claim/return | `inward_inspections`, `inspection_lines`, `supplier_returns` | receiving.gr, quality (defect lib) |

### 2.8 INVENTORY (core engine)

| Modul | Tanggung jawab | Data yang dimiliki | Dependensi |
|---|---|---|---|
| `inventory.ledger` | **Stock ledger append-only** — satu-satunya sumber kebenaran (BR-013); mencatat qty+cost per transaksi (BR-005) | `stock_ledger` | (ditulis hanya via ITS) |
| `inventory.stock` | Saldo teragregasi: on_hand/reserved/quality_hold/in_transit per item×warehouse×location×lot×roll (+ownership BR-001) | `stock_balances` (materialized dari ledger) | inventory.ledger |
| `inventory.transaction` | **Inventory Transaction Service (ITS)** — satu-satunya pintu tulis stok; atomic: dokumen+lines+ledger+saldo | `stock_movements` (header) | semua modul yang menggerakkan stok |
| `inventory.reservation` | Hard reservation saat MO release (BR-006), release/expire | `stock_reservations` | production.mo |
| `inventory.ops` | Transfer, adjustment (approval), opname + variance, leftover return | `stock_transfers`, `stock_adjustments`, `stock_opnames`, `opname_lines` | core.approval |

### 2.9 PRODUCTION

| Modul | Tanggung jawab | Data yang dimiliki | Dependensi |
|---|---|---|---|
| `production.mo` | Production Order: release → trigger reservation; status flow MO | `production_orders`, `mo_lines` | sales.order, pd.bom, inventory.reservation, core.approval |
| `production.issue` | Material issue ke cutting (aktual untuk fabric — OBD-014 default), return | `material_issues`, `material_issue_lines` | inventory.transaction, planning.cutplan |
| `production.wip` | WIP transfer antar proses (cutting→sewing→finishing→packing) | `wip_transfers` | inventory.transaction |
| `production.output` | Production receipt ke FG | `production_receipts` | inventory.transaction |

### 2.10 CUTTING

| Modul | Tanggung jawab | Data yang dimiliki | Dependensi |
|---|---|---|---|
| `cutting.order` | Cutting order dari cut plan; status | `cutting_orders` | planning.cutplan, production.mo |
| `cutting.marker` | Marker: panjang, efficiency, size ratio per lay | `markers`, `marker_lays` | master.material (width) |
| `cutting.execution` | Lay/spreading (layer count), roll allocation **shade-aware** (OBD-006), pemakaian aktual per roll, wastage, **leftover return ke inventory** | `lays`, `lay_rolls`, `cut_outputs` | receiving.fabric_rolls, inventory.transaction |
| `cutting.bundle` | Bundling: bundle header/detail + **barcode ticket** (OBD-013) | `bundles`, `bundle_pieces` | cutting.execution |

### 2.11 SEWING

| Modul | Tanggung jawab | Data yang dimiliki | Dependensi |
|---|---|---|---|
| `sewing.assignment` | Assign bundle → line/operator/machine per operasi | `line_assignments` | cutting.bundle, master.employee, master.production |
| `sewing.output` | Output per line/hari (BR-007), siap per operator/jam (scan bundle); target vs actual | `line_outputs`, `operator_outputs` | sewing.assignment, pd.routing (SAM) |
| `sewing.downtime` | Downtime log + kategori + approval supervisor (OBD-015) | `downtime_logs` | master.production |
| `sewing.efficiency` | Hitung efisiensi SAM (formula configurable, OBD-012) | (computed view/report) | sewing.output |

### 2.12 FINISHING

| Modul | Tanggung jawab | Data yang dimiliki | Dependensi |
|---|---|---|---|
| `finishing.ops` | Trimming, pressing, folding, repair; output | `finishing_outputs`, `repair_records` | production.wip |
| `finishing.washing` | Washing in-house atau link ke subcon (OBD-002) | `wash_records` | subcontracting (opsional) |

### 2.13 QUALITY

| Modul | Tanggung jawab | Data yang dimiliki | Dependensi |
|---|---|---|---|
| `quality.inspection` | Inline/roving, endline, final; sampling AQL engine ISO 2859-1 (BR-008): lot→code letter→sample size→Ac/Re | `inspections`, `inspection_samples`, `inspection_defects` | master.customer (AQL cfg), master.production (defect lib) |
| `quality.ncr` | NCR, disposition (repair/reject/second-grade/scrap — OBD-017/018), approval | `ncrs`, `dispositions` | quality.inspection, core.approval |
| `quality.rework` | Rework loop + counter batas rework | `rework_orders` | quality.ncr, production.wip |
| `quality.report` | Inspection report format per buyer | (view/report) | quality.inspection |

### 2.14 PACKING

| Modul | Tanggung jawab | Data yang dimiliki | Dependensi |
|---|---|---|---|
| `packing.instruction` | Packing instruction per SO: solid/ratio/mixed (OBD-020) | `packing_instructions` | sales.order |
| `packing.execution` | Packing list, carton (no, dimensi, GW/NW), carton detail per SKU; validasi toleransi shipment (OBD-019) | `packing_lists`, `cartons`, `carton_lines` | quality.inspection (PASS), packing.instruction |

### 2.15 SHIPPING / EXPORT

| Modul | Tanggung jawab | Data yang dimiliki | Dependensi |
|---|---|---|---|
| `shipping.plan` | Shipment plan vs delivery schedule; partial shipment | `shipments`, `shipment_lines` | sales.order, packing.execution |
| `shipping.docs` | Commercial invoice, packing list final, dokumen ekspor (LC-ready), container | `commercial_invoices`, `containers`, `export_documents` | shipping.plan |
| `shipping.tracking` | Status: PLANNED→STUFFING→DEPARTED→ARRIVED→INVOICED→PAID | (status di `shipments`) | — |

### 2.16 SUBCONTRACTING / JOB WORK

| Modul | Tanggung jawab | Data yang dimiliki | Dependensi |
|---|---|---|---|
| `subcon.order` | Job Work Order (print/bordir/washing/CMT), ke supplier tipe subcon | `job_work_orders`, `jwo_lines` | master.supplier, production.mo, core.approval |
| `subcon.flow` | Material out (ledger: transfer ke lokasi SUBCON — stok tetap milik company), return + QC, reject di subcon, aging | `subcon_movements` | inventory.transaction, quality.inspection |
| `subcon.cost` | Biaya jasa → actual cost MO terkait | `subcon_costs` | costing.actual |

### 2.17 COSTING

| Modul | Tanggung jawab | Data yang dimiliki | Dependensi |
|---|---|---|---|
| `costing.standard` | Standard/estimated cost (dari pd.costing, revisi per SO) | (read pd.costing; snapshot per SO) | pd.costing, sales.order |
| `costing.actual` | Actual cost per MO (BR-009): material (dari ledger issue), labor (output×rate), OH (Σ SAM×output × OH rate), subcon, wastage | `actual_costs`, `actual_cost_lines` | inventory.ledger (read), sewing.output, subcon.cost, master.finance |
| `costing.variance` | Estimated vs actual per MO/SO; margin per style/buyer | (view/report) | costing.standard, costing.actual |

### 2.18 FINANCE (full GL — DEC-2026-08-13-03)

| Modul | Tanggung jawab | Data yang dimiliki | Dependensi |
|---|---|---|---|
| `finance.ar` | AR invoice ke buyer, payment receipt, aging | `ar_invoices`, `ar_payments` | shipping.docs (CI), master.customer |
| `finance.ap` | AP dari supplier invoice, payment, aging | `ap_payments` | purchasing.invoice |
| `finance.journal` | **Full GL internal**: journal operasional otomatis (dari event) + journal umum/manual, period closing, inventory valuation, COGS, laporan keuangan (trial balance, P&L, balance sheet dasar), period lock (OBD-026) | `journals`, `journal_lines`, `accounting_periods` | master.finance (COA), inventory.ledger (read), costing.actual (read) |
| `finance.export` | **Opsional** — ekspor journal ke sistem eksternal (hanya bila kelak dibutuhkan; saat ini tidak ada software akuntansi eksternal) | `export_batches` | finance.journal |

### 2.19 REPORTING & DASHBOARD

| Modul | Tanggung jawab | Dependensi |
|---|---|---|
| `reporting.*` | Report per domain (FASE 1 §13) + dashboard Management/PPIC/Warehouse/Production/QC; traceability MRP & roll→carton | read-only ke semua modul (via query layer/view) |

---

## 3. ATURAN INTERAKSI LINTAS MODUL

| # | Aturan | Penjelasan |
|---|---|---|
| I-01 | Semua perubahan stok lewat **Inventory Transaction Service** | Modul manapun (GR, issue, return, leftover, shipment, adjustment) memanggil ITS; tidak ada UPDATE stok langsung (BR-013) |
| I-02 | Nomor dokumen lewat **core.numbering** | Dipanggil saat create dokumen; concurrency-safe (BR-010) |
| I-03 | Approval lewat **core.approval** | Modul mendaftarkan doc-type + matrix; engine mengelola step, delegasi, history |
| I-04 | Audit lewat **core.audit** | Dipanggil otomatis di service layer (interceptor), bukan manual per controller |
| I-05 | Traceability via `source_document`/`source_document_line` | Setiap dokumen turunan menyimpan referensi sumber (SO→MO→CUT→bundle→carton→shipment) |
| I-06 | MRP & costing membaca via query service, bukan menulis | planning & costing adalah *consumer*; tidak menulis tabel milik sales/inventory/production |
| I-07 | Domain events untuk decoupling | Contoh: `MO_RELEASED` → reservation; `INSPECTION_PASSED` → release QUALITY_HOLD; `SHIPMENT_CONFIRMED` → ledger SHIPMENT + AR draft. Implementasi awal: in-process events (Laravel Events + Horizon queue) |
| I-08 | Tidak ada sharing tabel tulis lintas modul | Satu tabel satu penulis (single-writer per aggregate) |

---

## 4. DEPENDENCY GRAPH (RINGKAS)

```
CORE (organization, user, rbac, approval, numbering, audit, settings)
  ↑ dipakai semua
MASTER DATA (customer, supplier, employee, product, material, uom, warehouse, production, finance)
  ↑
SALES ──► PD (style/bom/routing/costing) ──► PLANNING (mrp/cutplan)
  │                                           │
  │                                           ▼
  │                              PURCHASING ──► RECEIVING ──► INVENTORY ◄─── (ITS dipanggil semua)
  │                                           │                  ▲
  ▼                                           ▼                  │
PRODUCTION (MO) ──► CUTTING ──► SEWING ──► FINISHING ──► QUALITY ──► PACKING ──► SHIPPING ──► FINANCE(AR)
                       │             │                        │
                       └── leftover ─┴── rework loop ─────────┘
SUBCONTRACTING keluar/masuk di titik proses terkait (via INVENTORY lokasi SUBCON)
COSTING membaca: BOM, ledger, output, rates ──► variance
FINANCE: full GL internal (journal dari event + manual, period closing, laporan keuangan)
REPORTING membaca semua (read-only)
```

---

## 5. KEPUTUSAN ARSITEKTUR

| Item | Status | Catatan |
|---|---|---|
| Tech stack (TD-01) | ✅ RESOLVED (DEC-2026-08-13-02) | Laravel 13/PHP 8.5 + React/Next.js 16 + Redis 8 + Horizon/Reverb/Sanctum |
| Database engine | ✅ RESOLVED (DEC-2026-08-13-03) | **MySQL 8.x sementara** → migrasi terjadwal ke PostgreSQL; aturan portabilitas mengikat (Database Blueprint §7) |
| Deployment (TD-02) | ✅ RESOLVED (DEC-2026-08-13-03) | **On-premise** di pabrik (Docker), arsitektur cloud-ready untuk migrasi nanti |
| Monolith-modular vs microservices | ✅ RESOLVED | **Modular monolith** (single deploy, module boundaries ketat); ekstraksi microservice dimungkinkan nanti karena I-01..I-08 |
| Message broker untuk domain events | ✅ RESOLVED | In-process events (Laravel Events + Horizon queue); interface siap broker bila perlu |
| Perangkat shop floor (TD-03) | ⏳ sebelum Phase 6 | Rekomendasi browser-based (tablet/HP + scan gun) |
| Buyer portal (OBD-003) | ⏳ fase lanjut | — |

---

## 6. PENUTUP

Dokumen ini dikunci **v1.0**. Menjadi dasar struktur modul backend Laravel (`apps/api`) — satu modul per baris pada peta di atas, dengan boundary sesuai aturan I-01..I-08.
