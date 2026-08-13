# ERP GARMENT — DATABASE BLUEPRINT (FASE 3)

> **Status:** ✅ LOCKED v1.0 — disetujui pemilik 13 Agustus 2026
> **Tanggal:** 13 Agustus 2026
> **Dasar:** FASE 0 v1.0, FASE 1 Business Spec v1.0, MODULE_MAP v0.2, PROCESS_FLOW v0.2, BUSINESS_RULES v1.0, ROLES_PERMISSIONS v0.1, DEC-2026-08-13-01 s/d 03
> **Aturan:** blueprint ini menerjemahkan business rule (BR-xxx) menjadi struktur. Tidak ada kolom/tabel tanpa dasar rule.
> **Engine:** **MySQL 8.x** (sementara, on-premise) — desain wajib portabel ke PostgreSQL (DEC-2026-08-13-03, lihat §7).

---

## 1. KONVENSI GLOBAL

### 1.1 Kolom standar (semua tabel)
```
id            : BIGINT UNSIGNED, PK, auto increment (internal, BUKAN nomor dokumen)
company_id    : BIGINT, FK companies.id         — semua tabel master & transaksi (BR-011)
created_at    : DATETIME(6)
created_by    : BIGINT, FK users.id
updated_at    : DATETIME(6), null
updated_by    : BIGINT, FK users.id, null
deleted_at    : DATETIME, null                  — soft delete (master data saja)
```
Ledger, audit log, dan counter **tanpa** `updated_*` / `deleted_at` (append-only).

### 1.2 Prinsip
- **PK** = `id` numerik internal. **Nomor dokumen** = kolom `doc_no` unik per company (dari core.numbering, BR-010) — tidak bergantung pada `id`.
- **UQ** diberi prefix per company bila relevan: `uq_<table>_<kolom>` mis. `uq_sales_orders_company_docno (company_id, doc_no)`.
- **FK** selalu eksplisit, `ON DELETE RESTRICT` (tidak ada cascade delete antar modul).
- **Uang** = `DECIMAL(19,4)`; **qty** = `DECIMAL(18,4)`; **rate kurs** = `DECIMAL(18,6)`; persen = `DECIMAL(7,4)` — valid di MySQL & PostgreSQL.
- **Status** = `VARCHAR` + **CHECK constraint** (MySQL 8.0.16+ men-enforce CHECK; portabel ke PostgreSQL) — dilarang tipe `ENUM` MySQL (tidak portabel, lihat §7).
- Tabel **matrix line** untuk style×color×size (BR-020) — dilarang JSON untuk data relasional.
- `source_document_type` + `source_document_id` + `source_document_line_id` pada dokumen turunan (BR-120).
- Stok tidak boleh negatif: CHECK `on_hand >= 0`, `reserved >= 0`, `quality_hold >= 0` (BR-006).
- Index wajib: semua FK, `(company_id, doc_no)`, `(company_id, status)`, kolom tanggal dokumen, kolom traceability.
- Tabel bervolume sangat tinggi (`stock_ledger`, `audit_logs`, `operator_outputs`, `bundle_pieces`): desain append-only + kandidat partisi per `company_id`/periode (diterapkan saat implementasi DB).

### 1.3 Klasifikasi
- **M** = Master, **T** = Transaksi, **L** = Ledger/log (append-only), **C** = Config.

---

## 2. ERD (TEKS — RELASI KUNCI)

```
companies ─┬─ factories ─┬─ warehouses ── locations
           ├─ users ── user_roles ── roles ── role_permissions ── permissions
           ├─ doc_numbering_configs ── doc_number_counters
           ├─ approval_flows ── approval_flow_steps
           └─ customers, suppliers, employees, materials, styles, uoms, machines, lines, operations ...

styles ── colorways ── colors ; styles ── size_ranges ── sizes
styles ── boms ── bom_versions ── bom_lines ── materials
styles ── routings ── routing_versions ── routing_operations ── operations
styles ── cost_sheets ── cost_sheet_lines

customers ── sales_orders ── sales_order_lines (style×colorway×size)
sales_orders ── order_amendments ; sales_orders ── delivery_schedules

sales_orders ── mrp_runs ── mrp_requirements ── mrp_trace_lines (→ sales_order_lines, bom_lines)
mrp_requirements ── purchase_requests ── pr_lines ── purchase_orders ── po_lines
po_lines ── goods_receipts ── gr_lines ── fabric_rolls (per roll, BR-052)
gr_lines ── inward_inspections ── inspection_lines (→ defect_library)
gr_lines ── supplier_returns

sales_orders ── production_orders ── mo_lines
production_orders ── stock_reservations (BR-006/060)
production_orders ── cut_plans ── cut_plan_lays ── cutting_orders
cutting_orders ── markers ── marker_lays ── lays ── lay_rolls ── fabric_rolls
lays ── cut_outputs ── bundles ── bundle_pieces
bundles ── line_assignments ── lines/employees/machines
bundles ── line_outputs / operator_outputs ; lines ── downtime_logs
bundles ── wip_transfers (cutting→sewing→finishing→packing)
production_orders ── material_issues ── material_issue_lines (→ fabric_rolls)

bundles/output ── inspections ── inspection_samples + inspection_defects (→ defect_library)
inspections ── ncrs ── dispositions ; ncrs ── rework_orders

sales_orders ── packing_instructions
production_orders ── packing_lists ── cartons ── carton_lines (style×color×size)
packing_lists ── shipments ── shipment_lines ; shipments ── containers
shipments ── commercial_invoices ── ci_lines ── ar_invoices ── ar_payments

production_orders ── job_work_orders ── jwo_lines ── subcon_movements, subcon_costs

SEMUA pergerakan stok ── stock_ledger ──(agregasi)──► stock_balances
stock_opnames ── opname_lines ; stock_adjustments ── adjustment_lines ; stock_transfers ── transfer_lines

production_orders ── actual_costs ── actual_cost_lines
cost_sheets(approved) ── standard_costs (snapshot per SO)

journals ── journal_lines ── chart_of_accounts ; accounting_periods (full GL — DEC-03)
supplier_invoices ── ap_payments ; export_batches (opsional)

audit_logs : polymorphic (document_type, document_id) — semua modul
```

---

## 3. DAFTAR TABEL PER MODUL

Format ringkas per tabel: **Purpose | PK | FK kunci | Unique | Index tambahan | Rule**. Kolom audit standar (§1.1) berlaku untuk semua dan tidak diulang.

### 3.1 CORE

| Tabel | Class | Purpose | Unique / Index penting | Rule |
|---|---|---|---|---|
| `companies` | M | Company (kode, nama, currency dasar) | uq(code) | BR-011 |
| `factories` | M | Factory/branch per company | uq(company_id, code) | BR-011 |
| `users` | M | Akun user (name, email, is_active) | uq(company_id, email) | BR-111 |
| `user_credentials` | M | Hash password (Argon2/bcrypt), reset token, lockout counter | fk users; idx(user_id) | BR-111 |
| `user_companies` | M | Akses user ke multi company | uq(user_id, company_id) | BR-011 |
| `roles` | M | Role (code, name) | uq(company_id, code) | BR-110 |
| `permissions` | M | Katalog permission `domain.entity.action` | uq(code) | BR-110 |
| `role_permissions` | M | Mapping role↔permission | uq(role_id, permission_id) | BR-110 |
| `user_roles` | M | Mapping user↔role | uq(user_id, role_id) | BR-110 |
| `approval_flows` | C | Definisi flow per doc-type (sequential/parallel) | uq(company_id, doc_type, version) | BR-015 |
| `approval_flow_steps` | C | Step: level, role approver, min–max nilai | idx(flow_id, step_no) | BR-015 |
| `approval_requests` | T | Instance approval per dokumen (status: PENDING/APPROVED/REJECTED/REVISION/CANCELLED) | uq(company_id, doc_type, doc_id, active_flag); idx(status) | BR-015 |
| `approval_step_instances` | T | Keputusan per step (approver aktual, delegasi, waktu, note) | idx(request_id, step_no) | BR-015 |
| `doc_numbering_configs` | C | Prefix, pola, digit, reset tahunan per doc-type | uq(company_id, doc_type) | BR-010 |
| `doc_number_counters` | C | Counter per (company, prefix, tahun); increment dalam transaksi terkunci (SELECT ... FOR UPDATE) | uq(company_id, prefix, period_year) | BR-010 |
| `audit_logs` | L | who/what/when/before→after(JSON diff)/IP/device/document | idx(company_id, doc_type, doc_id), idx(created_at) — **tanpa update/delete** | BR-016 |
| `settings` | C | Key-value settings per company (formula, threshold, feature flag) | uq(company_id, key) | ⚙️ |
| `notifications` | T | Notifikasi in-app/email (payload, read_at) | idx(user_id, read_at) | — |
| `integration_jobs` | T | Job import/export (type, file, status, error log) | idx(company_id, type, status) | OBD-032 |

### 3.2 MASTER DATA

| Tabel | Class | Purpose | Unique / Index penting | Rule |
|---|---|---|---|---|
| `customers` | M | Buyer (code, brand, negara, currency, payment term, incoterm, shipment_tolerance_pct) | uq(company_id, code) | BR-021 |
| `customer_aql_configs` | C | AQL per buyer: inspection level, AQL critical/major/minor, report format | uq(company_id, customer_id) | BR-008 |
| `suppliers` | M | Supplier: type (FABRIC/TRIM/PACKAGING/SUBCON), lead time, currency, term | uq(company_id, code); idx(type) | — |
| `employees` | M | Karyawan/operator (NIK, section, line_id, skill, is_operator) | uq(company_id, nik); idx(line_id) | — |
| `styles` | M | Style master (style_no, buyer_style_ref, season, kategori WOVEN/KNIT/OTHER, product group, lifecycle DEVELOPMENT/ACTIVE/DISCONTINUED) | uq(company_id, style_no); idx(customer_id) | BR-023 |
| `colors` | M | Warna (code, name, buyer_color_name) | uq(company_id, code) | — |
| `colorways` | M | Kombinasi warna per style + lab-dip ref + shade_group | uq(style_id, color_id); idx(shade_group_id) | BR-053 |
| `shade_groups` | M | Grup shade untuk kontrol lay | uq(company_id, code) | BR-053 |
| `sizes` | M | Size (code, urutan) | uq(company_id, code) | — |
| `size_ranges` | M | Range size per buyer/style (S–XXL dll) | uq(company_id, code) | — |
| `size_range_lines` | M | Anggota size dalam range + urutan | uq(size_range_id, size_id) | — |
| `uoms` | M | Satuan (PCS, MTR, YDS, KG, CONE, GRS...) | uq(company_id, code) | BR-002 |
| `uom_conversions` | C | Konversi standar antar UOM per material | uq(material_id, from_uom, to_uom) | BR-002 |
| `materials` | M | Material: type (FABRIC/TRIM/PACKAGING), kode, nama; fabric: composition, GSM, width, shrinkage_std, buy_uom, use_uom; class untuk backflush flag | uq(company_id, code); idx(type) | BR-002/041 |
| `material_uom_conversions` | C | Konversi default per material (override per roll di GR) | uq(material_id, uom) | BR-002 |
| `warehouses` | M | Gudang: type (RM/WIP/FG/TRIM/SUBCON_VIRTUAL) | uq(company_id, code); idx(type) | BR-090 |
| `locations` | M | Bin/location per warehouse | uq(warehouse_id, code) | — |
| `machines` | M | Mesin (type, line_id) | uq(company_id, code) | — |
| `lines` | M | Line jahit (section, kapasitas std, manpower std) | uq(company_id, code) | — |
| `operations` | M | Operasi jahit (code, name, machine_type, grade) | uq(company_id, code) | — |
| `operation_versions` | M | SMV/SAM versioned per operation (valid_from) | uq(operation_id, version) | BR-033 |
| `defect_library` | M | Defect code, kategori (FABRIC/WORKMANSHIP/MEASUREMENT/PACKAGING/OTHER), severity (CRITICAL/MAJOR/MINOR) | uq(company_id, code) | BR-072 |
| `chart_of_accounts` | M | COA (code, name, type, normal balance) | uq(company_id, code) | BR-101 |
| `currencies` | M | Mata uang | uq(company_id, code) | BR-102 |
| `exchange_rates` | M | Rate per currency per tanggal | uq(currency_id, rate_date) | BR-102 |
| `overhead_rates` | C | OH rate per menit per company per periode | uq(company_id, period) | BR-009 |
| `line_cost_rates` | C | Cost-per-minute per line per periode (CM costing) | uq(line_id, period) | BR-009 |

### 3.3 SALES

| Tabel | Class | Purpose | Unique / Index penting | Rule |
|---|---|---|---|---|
| `sales_orders` | T | SO: doc_no, customer_id, buyer_po_no, currency+rate, order_date, ex_factory_date, status baseline, tolerance_pct (override buyer) | uq(company_id, doc_no); idx(customer_id, status); uq(customer_id, buyer_po_no) | BR-010/021 |
| `sales_order_lines` | T | Matrix line: style_id, colorway_id, size_id, qty, price | uq(so_id, style_id, colorway_id, size_id) | BR-020 |
| `order_amendments` | T | Amendment: doc_no, SO ref, perubahan (line delta), reason, status | uq(company_id, doc_no); idx(so_id) | BR-022 |
| `delivery_schedules` | T | Jadwal kirim per SO (date, qty, destination) | idx(so_id) | — |
| `inquiries` | T | Enquiry/quotation awal (ringan) | uq(company_id, doc_no) | — |

### 3.4 PRODUCT DEVELOPMENT

| Tabel | Class | Purpose | Unique / Index penting | Rule |
|---|---|---|---|---|
| `style_specs` | M | Spec sheet per style (deskripsi, catatan konstruksi) | uq(style_id, version) | — |
| `measurement_charts` | M | Measurement chart per style | uq(style_id, version) | — |
| `measurement_lines` | M | Point of measure × size × tolerance | uq(chart_id, pom_code, size_id) | — |
| `tech_packs` | M | Dokumen tech pack (file refs S3) | idx(style_id) | — |
| `samples` | T | Sample: stage (PROTO/FIT/PP/TOP), version, status approval buyer | uq(company_id, doc_no); idx(style_id, stage) | — |
| `sample_approvals` | T | Komentar/approval buyer per sample | idx(sample_id) | — |
| `boms` | M | Header BOM per style (current_version) | uq(style_id) | BR-030 |
| `bom_versions` | M | Versi BOM: version_no, status (DRAFT/SUBMITTED/APPROVED/OBSOLETE) | uq(bom_id, version_no) | BR-030 |
| `bom_lines` | M | Material per versi: material_id, colorway_id (nullable=semua), qty_per_pcs, uom (pakai), wastage_pct, shrinkage_pct, consumption_estimated, is_backflush | idx(bom_version_id); idx(material_id) | BR-031/032/041 |
| `routings` | M | Header routing per style | uq(style_id) | BR-030 |
| `routing_versions` | M | Versi routing + total SAM | uq(routing_id, version_no) | BR-030 |
| `routing_operations` | M | Operasi per versi: seq, operation_id, smv, machine_type | uq(routing_version_id, seq) | BR-033 |
| `cost_sheets` | T | Estimated cost sheet per style (doc_no, version, status, FOB, margin) | uq(company_id, doc_no); idx(style_id) | BR-100 |
| `cost_sheet_lines` | T | Komponen: type (FABRIC/TRIM/CM/OVERHEAD/SUBCON/OTHER), qty, rate, amount | idx(cost_sheet_id) | BR-100 |

### 3.5 PLANNING

| Tabel | Class | Purpose | Unique / Index penting | Rule |
|---|---|---|---|---|
| `mrp_runs` | T | Run MRP: doc_no, run_at, horizon, status | uq(company_id, doc_no) | BR-121 |
| `mrp_requirements` | T | Kebutuhan nett per material: gross, on_hand, reserved, open_po, safety_stock, nett, planned_order_qty | idx(run_id, material_id) | BR-121 |
| `mrp_trace_lines` | T | Trace: requirement → so_line → bom_line (gross contribution) | idx(requirement_id); idx(so_line_id) | BR-121 |
| `production_plans` | T | Plan per line per periode (target qty) | uq(company_id, line_id, period, style_id) | — |
| `line_loading` | T | Load vs kapasitas per line per tanggal | uq(line_id, plan_date, mo_id) | — |
| `cut_plans` | T | Cut plan per MO: jumlah lay, total qty | uq(company_id, doc_no); idx(mo_id) | — |
| `cut_plan_lays` | T | Lay plan: size ratio, layer count, est marker length | idx(cut_plan_id) | — |

### 3.6 PURCHASING

| Tabel | Class | Purpose | Unique / Index penting | Rule |
|---|---|---|---|---|
| `purchase_requests` | T | PR: doc_no, source (MRP/manual), status | uq(company_id, doc_no) | — |
| `pr_lines` | T | Item, qty, uom, need_date, ref mrp_requirement | idx(pr_id); idx(mrp_requirement_id) | BR-120 |
| `rfqs` | T | RFQ ke multi supplier | uq(company_id, doc_no) | — |
| `quotations` | T | Quotation supplier (price, lead time, term) | idx(rfq_id, supplier_id) | — |
| `quotation_lines` | T | Detail harga per item | idx(quotation_id) | — |
| `purchase_orders` | T | PO: doc_no, supplier, currency+rate, term, status | uq(company_id, doc_no); idx(supplier_id, status) | BR-010 |
| `po_lines` | T | Item, qty (UOM beli), price, received_qty (agregat), ref pr_line | uq(po_id, line_no); idx(material_id) | BR-051 |
| `supplier_invoices` | T | Invoice supplier + hasil 3-way match (MATCHED/MISMATCH/PENDING) | uq(company_id, doc_no); idx(supplier_id) | BR-050 |
| `supplier_invoice_lines` | T | Match ke po_line + gr_line, qty, price | idx(invoice_id); idx(po_line_id) | BR-050 |

### 3.7 RECEIVING & INWARD QC

| Tabel | Class | Purpose | Unique / Index penting | Rule |
|---|---|---|---|---|
| `goods_receipts` | T | GR: doc_no, po ref, warehouse, received_date, status | uq(company_id, doc_no); idx(po_id) | — |
| `gr_lines` | T | Item, qty terima (UOM beli), status (QUALITY_HOLD/RELEASED/REJECTED_RETURNED) per line | idx(gr_id); idx(po_line_id) | BR-004 |
| `fabric_rolls` | T | **Per roll**: roll_no, gr_line, lot/batch, shade_group, qty_beli, qty_meter_actual, conversion_rate (tersimpan), gsm_actual, width_actual, status | uq(company_id, roll_no); idx(material via gr_line); idx(shade_group_id) | BR-002/003/052 |
| `inward_inspections` | T | Inspeksi: doc_no, gr ref, inspector, hasil (PASS/FAIL/PARTIAL) | uq(company_id, doc_no) | BR-004 |
| `inward_inspection_lines` | T | Per roll/line: 4-point points, shrinkage%, gsm, shade verdict, defect refs | idx(inspection_id); idx(roll_id); fk defect_library | BR-072 |
| `supplier_returns` | T | Return ke supplier (dari FAIL) + claim info | uq(company_id, doc_no); idx(gr_id) | BR-004 |

### 3.8 INVENTORY

| Tabel | Class | Purpose | Unique / Index penting | Rule |
|---|---|---|---|---|
| `stock_ledger` | L | **Append-only**: movement_type (OPENING/PURCHASE_RECEIPT/PURCHASE_RETURN/QUALITY_RELEASE/TRANSFER_IN/TRANSFER_OUT/MATERIAL_ISSUE/PRODUCTION_RETURN/PRODUCTION_RECEIPT/ADJUSTMENT/OPNAME_ADJUSTMENT/SUBCON_OUT/SUBCON_IN/SHIPMENT), item_type (MATERIAL/WIP/FG), material_id/style variant ref, warehouse, location, lot, roll, ownership (COMPANY/BUYER), qty_in, qty_out, uom, unit_cost, total_cost, source_document_type/id/line_id, running_balance | idx(company_id, material_id, warehouse_id, created_at); idx(source_document); idx(roll_id) | BR-001/005/013 |
| `stock_balances` | T | Saldo materialized: item×warehouse×location×lot×roll×ownership: on_hand, reserved, quality_hold, in_transit_subcon, avg_cost — CHECK semua ≥ 0 | uq(company_id, item_key...); idx(material_id, warehouse_id) | BR-005/006 |
| `stock_movements` | T | Header dokumen movement (mengelompokkan ledger entries per dokumen) | uq(company_id, doc_no) | BR-013 |
| `stock_reservations` | T | Hard reservation: mo_id, bom ref, material, qty_reserved, qty_issued, status (ACTIVE/PARTIAL_ISSUED/FULLY_ISSUED/RELEASED) | idx(mo_id); idx(material_id, warehouse_id, status) | BR-006/060 |
| `stock_transfers` / `*_lines` | T | Transfer antar warehouse/location | uq(company_id, doc_no) | PF-12 |
| `stock_adjustments` / `*_lines` | T | Adjustment ber-approval (+/− qty & cost) | uq(company_id, doc_no) | BR-017 |
| `stock_opnames` / `*_lines` | T | Opname: freeze snapshot, qty fisik (per roll), variance, status | uq(company_id, doc_no) | PF-12 |

### 3.9 PRODUCTION

| Tabel | Class | Purpose | Unique / Index penting | Rule |
|---|---|---|---|---|
| `production_orders` | T | MO: doc_no, so ref, style, qty, planned start/end, status (.../RELEASED/CUTTING/SEWING/FINISHING/QC/PACKED/CLOSED) | uq(company_id, doc_no); idx(so_id, status) | BR-060 |
| `mo_lines` | T | Qty per colorway×size | uq(mo_id, colorway_id, size_id) | BR-020 |
| `material_issues` / `*_lines` | T | Issue ke cutting: per roll (fabric) / lot (trim), qty; ref reservation | uq(company_id, doc_no); idx(mo_id); idx(roll_id) | BR-041/052 |
| `wip_transfers` / `*_lines` | T | Pindah WIP antar proses: from_stage→to_stage (CUTTING/SEWING/FINISHING/PACKING), bundle refs | uq(company_id, doc_no); idx(bundle via lines) | BR-064 |
| `production_receipts` / `*_lines` | T | FG receipt dari packing ke gudang FG | uq(company_id, doc_no); idx(mo_id) | PF-09 |

### 3.10 CUTTING

| Tabel | Class | Purpose | Unique / Index penting | Rule |
|---|---|---|---|---|
| `cutting_orders` | T | CUT: doc_no, mo ref, cut_plan ref, status | uq(company_id, doc_no); idx(mo_id) | — |
| `markers` | T | Marker: length, efficiency_pct (warning threshold dari settings) | idx(cutting_order_id) | PF-05 |
| `marker_lays` | T | Size ratio per marker | idx(marker_id) | — |
| `lays` | T | Eksekusi lay: layer count, panjang aktual, tanggal | idx(cutting_order_id) | — |
| `lay_rolls` | T | Roll terpakai per lay: roll_id, meter_used, leftover_meter, shade_group (validasi BR-053) | uq(lay_id, roll_id); idx(roll_id) | BR-042/053 |
| `cut_outputs` | T | Output per lay: colorway×size qty | idx(lay_id) | — |
| `bundles` | T | Bundle: **barcode unik**, style/color/size, qty, status (CUT/IN_SEWING/SEWN/FINISHED/QC_PASS/REWORK) | uq(company_id, barcode); idx(cutting_order_id, status) | BR-061 |
| `bundle_pieces` | T | Detail pcs per bundle (opsional; untuk tracking per piece) | idx(bundle_id) | BR-061 |

### 3.11 SEWING & FINISHING

| Tabel | Class | Purpose | Unique / Index penting | Rule |
|---|---|---|---|---|
| `line_assignments` | T | Assign bundle→line (+operation, operator, machine saat scan-mode) | idx(bundle_id); idx(line_id, assign_date) | BR-007 |
| `line_outputs` | T | Output per line per hari: qty, target, achievement% (generated) | uq(line_id, output_date, mo_id) | BR-007 |
| `operator_outputs` | T | (Fase lanjut) scan per operator per jam per bundle — append-heavy | idx(operator_id, ts); idx(bundle_id) | BR-007 |
| `downtime_logs` | T | Downtime: line, kategori (MACHINE/MATERIAL/POWER/SETTING/OTHER), menit, approval supervisor | idx(line_id, log_date) | BR-063 |
| `finishing_outputs` | T | Output finishing (trim/press/fold) per bundle | idx(bundle_id) | — |
| `repair_records` | T | Repair (dari QC/rework) | idx(bundle_id); idx(ncr_id) | — |
| `wash_records` | T | Washing in-house atau ref job_work_order | idx(mo_id) | OBD-002 |

### 3.12 QUALITY

| Tabel | Class | Purpose | Unique / Index penting | Rule |
|---|---|---|---|---|
| `inspections` | T | Inspeksi: doc_no, type (INLINE/ROVING/ENDLINE/FINAL), ref (mo/bundle/shipment lot), lot_size, aql_level, sample_size (dari engine), accept_no, reject_no, result (PASS/FAIL/REWORK/HOLD) | uq(company_id, doc_no); idx(mo_id, type) | BR-008 |
| `inspection_samples` | T | Sampel yang dicek (bundle/pcs ref) | idx(inspection_id) | BR-008 |
| `inspection_defects` | T | Defect per sample: defect_id (library), severity, qty | idx(inspection_id); idx(defect_id) | BR-072 |
| `ncrs` | T | NCR: doc_no, source inspection, status | uq(company_id, doc_no) | — |
| `dispositions` | T | Disposition: action (REWORK/REPAIR/REJECT/SECOND_GRADE/SCRAP), qty, approver | idx(ncr_id) | BR-070/071 |
| `rework_orders` | T | Rework: target stage, qty, rework_count, re-inspection ref | idx(ncr_id); idx(bundle_id) | BR-070 |

### 3.13 PACKING & SHIPPING

| Tabel | Class | Purpose | Unique / Index penting | Rule |
|---|---|---|---|---|
| `packing_instructions` | T | Instruksi per SO: type (SOLID/RATIO/MIXED), ratio detail | uq(so_id, version) | BR-024 |
| `packing_lists` | T | PKL: doc_no, mo/so ref, status | uq(company_id, doc_no) | — |
| `cartons` | T | Carton: carton_no (unik per shipment), dimensi, gw, nw | uq(packing_list_id, carton_no) | BR-081 |
| `carton_lines` | T | Isi carton: style×color×size qty (divalidasi ke instruction & QC_PASS) | idx(carton_id); idx(bundle ref opsional) | BR-080/081 |
| `shipments` | T | SHP: doc_no, so refs, etd/eta, container, status (PLANNED/STUFFING/DEPARTED/ARRIVED/INVOICED/PAID) | uq(company_id, doc_no); idx(status) | BR-083 |
| `shipment_lines` | T | Carton/pkl dalam shipment + validasi toleransi | idx(shipment_id) | BR-082 |
| `containers` | T | Container (no, size, seal) | idx(shipment_id) | — |
| `commercial_invoices` | T | CI: doc_no, currency+rate, total, LC refs | uq(company_id, doc_no); idx(shipment_id) | BR-102 |
| `export_documents` | T | Dokumen ekspor lain (COO, LC docs, file refs) | idx(shipment_id) | — |

### 3.14 SUBCONTRACTING

| Tabel | Class | Purpose | Unique / Index penting | Rule |
|---|---|---|---|---|
| `job_work_orders` | T | JW: doc_no, subcon supplier, process (PRINT/EMBROIDERY/WASHING/CMT), mo ref, status | uq(company_id, doc_no); idx(supplier_id) | BR-090 |
| `jwo_lines` | T | Item/bundle keluar: qty, expected return | idx(jwo_id) | — |
| `subcon_movements` | T | Out/in ke lokasi SUBCON (via ledger) + aging calc | idx(jwo_id); idx(ledger ref) | BR-090 |
| `subcon_costs` | T | Biaya jasa per JW → actual cost MO | idx(jwo_id); idx(mo_id) | BR-091 |

### 3.15 COSTING

| Tabel | Class | Purpose | Unique / Index penting | Rule |
|---|---|---|---|---|
| `standard_costs` | T | Snapshot cost sheet approved per SO (basis variance) | uq(so_id, cost_sheet_id) | BR-100 |
| `actual_costs` | T | Actual per MO: material, labor, overhead, subcon, wastage, total, cost_per_pcs | uq(mo_id) | BR-009 |
| `actual_cost_lines` | T | Rincian per komponen + sumber (ledger/output/JW refs) | idx(actual_cost_id) | BR-009 |

### 3.16 FINANCE (full GL — DEC-03)

| Tabel | Class | Purpose | Unique / Index penting | Rule |
|---|---|---|---|---|
| `ar_invoices` / `*_lines` | T | Invoice buyer (dari CI), due, currency | uq(company_id, doc_no); idx(customer_id) | — |
| `ar_payments` | T | Payment receipt + selisih kurs | uq(company_id, doc_no) | BR-102 |
| `ap_payments` | T | Payment ke supplier/subcon | uq(company_id, doc_no); idx(supplier_invoice_id) | — |
| `journals` / `journal_lines` | T | Journal operasional (otomatis dari event) + journal umum/manual; balanced check (Σdebit=Σcredit) | uq(company_id, doc_no); idx(period); idx(account via lines) | BR-101 |
| `accounting_periods` | C | Periode + is_locked + period closing ref | uq(company_id, period) | BR-103 |
| `export_batches` | T | Batch ekspor journal (**opsional** — hanya bila kelak perlu integrasi eksternal) | idx(company_id, period) | BR-101 |

---

## 4. STATUS (VARCHAR + CHECK — TERKONTROL)

| Entitas | Nilai |
|---|---|
| Dokumen umum (baseline) | `DRAFT, SUBMITTED, APPROVED, IN_PROGRESS, CLOSED, REJECTED, CANCELLED` |
| MO (tambahan) | `RELEASED, CUTTING, SEWING, FINISHING, QC, PACKED` |
| GR line / roll | `QUALITY_HOLD, RELEASED, REJECTED_RETURNED` |
| Bundle | `CUT, IN_SEWING, SEWN, FINISHED, QC_PASS, REWORK` |
| Inspection | `IN_PROGRESS, PASS, FAIL, REWORK, HOLD` |
| Disposition | `REWORK, REPAIR, REJECT, SECOND_GRADE, SCRAP` |
| Reservation | `ACTIVE, PARTIAL_ISSUED, FULLY_ISSUED, RELEASED` |
| Shipment | `PLANNED, STUFFING, DEPARTED, ARRIVED, INVOICED, PAID` |
| Approval request | `PENDING, APPROVED, REJECTED, REVISION, CANCELLED` |
| Supplier invoice match | `PENDING, MATCHED, MISMATCH` |

Status disimpan sebagai `VARCHAR` + CHECK constraint (portabel MySQL↔PostgreSQL); penambahan nilai = perubahan blueprint (versioned).

---

## 5. CONSTRAINT & INTEGRITAS KUNCI

1. `stock_balances`: CHECK `on_hand >= 0 AND reserved >= 0 AND quality_hold >= 0` (BR-006).
2. `stock_ledger`: tidak ada UPDATE/DELETE (di-enforce via permission DB & service); `qty_in` XOR `qty_out` > 0.
3. `journal`: CHECK Σdebit = Σcredit (divalidasi service + constraint periode locked, BR-103).
4. Semua `doc_no`: unik per `(company_id, doc_type)` via numbering service (BR-010).
5. FK `ON DELETE RESTRICT` menyeluruh; master yang dipakai transaksi hanya boleh soft delete.
6. `bom_lines`, `routing_operations` selalu mengacu `bom_versions`/`routing_versions` berstatus APPROVED saat dipakai transaksi (divalidasi service, BR-030).
7. Idempotency: endpoint mutasi menerima `request_id` unik untuk mencegah double-submit (retry-safe).

## 6. STRATEGI DATA BESAR & RETENSI

- `stock_ledger`, `audit_logs`: append-only; partisi per periode (bulanan) saat volume menuntut; arsip read-only.
- `operator_outputs`, `bundle_pieces`: index `(ts)`; agregasi harian ke tabel summary untuk dashboard.
- Report berat via view/materialized summary, bukan scan tabel transaksi (FASE 1 §13).

## 7. ENGINE DB & PORTABILITAS (DEC-2026-08-13-03)

| Item | Keputusan |
|---|---|
| Engine awal | **MySQL 8.x** (on-premise, Docker) |
| Target jangka menengah | **PostgreSQL** (migrasi terjadwal, dengan dry-run) |
| ORM/migration | Laravel Eloquent + Migration (DEC-02) |
| Search | **MySQL FULLTEXT** dulu → OpenSearch bila perlu |

### Aturan portabilitas MySQL → PostgreSQL (mengikat coding)
1. Semua akses DB lewat Eloquent/Query Builder — **dilarang raw SQL spesifik-engine** tanpa lapisan abstraksi.
2. Dilarang fitur spesifik-engine: partial index & operator `jsonb` (PostgreSQL), stored procedure/trigger bisnis (MySQL). Business rule tetap di service layer.
3. Status = `VARCHAR` + CHECK (bukan tipe ENUM MySQL).
4. `DECIMAL` untuk uang/qty — wajib di kedua engine.
5. JSON hanya untuk label i18n & audit diff (bukan data relasional); gunakan tipe `JSON` standar yang ada di kedua engine.
6. Locking (`SELECT ... FOR UPDATE`) untuk numbering & reservasi — tersedia di kedua engine.

---

## PENUTUP

Dokumen ini dikunci **v1.0**. Perubahan skema berikutnya (kolom/tabel/constraint) hanya via OBD/DEC baru + migration ber-review — tidak ada migration destructive tanpa persetujuan pemilik (master prompt aturan 23).
