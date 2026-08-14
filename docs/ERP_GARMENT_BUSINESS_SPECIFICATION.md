# ERP GARMENT — BUSINESS SPECIFICATION (FASE 1)

> **Status:** ✅ LOCKED v1.1 — v1.0 disetujui pemilik 13 Agu 2026; v1.1: + BEP analysis (DEC-2026-08-14-01)
> **Tanggal:** 14 Agustus 2026
> **Dasar:** FASE 0 Business Discovery (LOCKED v1.0) + DECISION_LOG DEC-2026-08-13-01 s/d DEC-2026-08-14-01
> **Aturan:** Dokumen ini adalah sumber kebenaran bisnis. Kode mengikuti dokumen ini, bukan sebaliknya. Item yang belum diputuskan ditandai `OBD-NNN` (lihat FASE 0 & DECISION_LOG).

---

## 1. SYSTEM VISION

ERP khusus industri garment yang mengelola **satu rantai nilai utuh**: dari Buyer PO sampai shipment dan costing aktual — dengan inventory real-time berbasis stock ledger, produksi multi-tahap yang terlacak per bundle, quality control berstandar AQL, dan costing yang bisa dibandingkan estimated vs actual.

Prinsip sistem:
1. **Satu sumber kebenaran** — sekali input, dipakai semua departemen (tidak ada re-entry).
2. **Stock ledger adalah kebenaran inventory** — saldo selalu dapat diturunkan dari ledger.
3. **Setiap dokumen ter-audit** — who/what/when/before/after.
4. **Business rule terkonfigurasi, bukan hard-code** (approval matrix, toleransi, AQL per buyer, formula efisiensi).
5. **Shop-floor first** — operator cukup scan; kompleksitas ditanggung sistem.
6. **Siap tumbuh** — multi-company, multi-warehouse, multi-currency, multi-language sejak level schema.

## 2. BUSINESS SCOPE

### In-scope (produk ini)
- Sales/Merchandising: Buyer PO, SO, amendment, delivery schedule.
- Product Development: style, sample, size spec, BOM (versioned), routing + SMV, tech pack, pre-production costing.
- Planning (PPIC): MRP dengan netting traceable, production plan, capacity/line loading, cut plan.
- Purchasing: PR, RFQ & comparison, PO, supplier invoice matching (3-way: PO–GR–invoice).
- Receiving & Inward QC: GR roll-level, fabric inspection (4-point), shrinkage/GSM/shade, quality hold.
- Inventory: multi-warehouse (RM/WIP/FG/Trim/Subcon-in-transit), ledger, reservation, transfer, adjustment, opname, lot/roll, dual UOM kain.
- Production: MO, material issue, WIP antar proses, output.
- Cutting: cutting order, marker efficiency, lay, roll allocation (shade-aware), bundling + barcode ticket, leftover return, wastage.
- Sewing: line/operator/machine, target vs actual, SAM efficiency, downtime, reject/rework.
- Finishing: trimming, pressing, repair, (washing — in-house/subcon).
- Quality: inline/endline/final AQL (ISO 2859-1), defect library, NCR, disposition, report format per buyer.
- Packing: assortment/ratio pack, carton detail, packing list.
- Shipping/Export: shipment plan, container, commercial invoice, dokumen ekspor (LC-ready).
- Subcontracting/Job Work: material out → tracking → return, biaya jasa ke costing.
- Costing: estimated/standard/actual, variance, margin per style/order.
- **Finance — FULL GL (OBD-024 RESOLVED, DEC-2026-08-13-03):** COA, journal (operasional + umum/manual), AR (invoice buyer), AP (supplier/subcon), cash/bank, period closing, inventory valuation, COGS, revenue, expense, laporan keuangan (trial balance, P&L, balance sheet dasar). Perusahaan sebelumnya Excel/manual → tidak ada integrasi akuntansi eksternal; ekspor journal bersifat opsional.
- **BEP (Break-Even Point) analysis — milik Accounting (DEC-2026-08-14-01, BR-104):** BEP factory-wide per bulan (utama) + per style (sekunder); computed report dari GL + costing, tanpa tabel baru.
- Reporting & Dashboard per domain.

### Out-of-scope (fase awal, hook disiapkan)
- Payroll & HR lengkap (data employee/operator tetap ada untuk produksi).
- Buyer portal eksternal (OBD-003).
- Integrasi CAD/marker software (impor manual/CSV dulu).
- E-commerce/omnichannel (referensi Infor — bukan kebutuhan CMT/FOB factory).
- Demand forecasting AI (planning berbasis order confirmed dulu).

## 3. ACTORS

Mengacu FASE 0 bagian 3 (18 aktor): Owner/Management, Admin Sistem, Sales/Merchandiser, Product Developer, IE, PPIC, Purchasing, Warehouse (RM/WIP/FG), Fabric Inspector, Cutting (supervisor/spreader/bundler), Sewing (supervisor/operator), Finishing, QC (inline/endline/final), Packing, Shipping/Export, Subcon Coordinator, Finance/Accounting, Buyer (eksternal, nanti).

## 4. ROLES

16 role sistem dengan permission granular `domain.entity.action`:

| Role | Ringkasan hak |
|---|---|
| Super Admin | Semua, termasuk konfigurasi numbering & approval matrix |
| Admin | Master data, user (tanpa ubah permission schema) |
| Sales | customer.*, sales.order.* (create/update/submit), costing.view |
| Merchandiser | Sales + style.view, sample.*, tracking order end-to-end |
| Product Development | style.*, bom.*, routing.*, sizespec.*, techpack.* |
| PPIC | mrp.run, productionplan.*, mo.*, cutplan.*, shortage.view, reservation.* |
| Purchasing | pr.*, rfq.*, po.* (create/update/submit), supplier.* |
| Warehouse | gr.*, stock.*, issue.*, transfer.*, opname.* (create/submit) |
| Cutting | cuttingorder.execute, lay.*, bundle.*, leftover.return |
| Production (Sewing/Finishing) | output.*, downtime.*, wip.transfer |
| QC | inspection.*, defect.*, ncr.*, disposition.* |
| Packing | packinglist.*, carton.* |
| Shipping | shipment.*, exportdoc.* |
| Finance | invoice.*, payment.*, journal.*, valuation.view |
| Accounting | finance + period.lock, journal.approve, financial report, **bep.view** |
| Management | *.view semua domain, dashboard, approval level akhir |

Aturan: permission dicek **server-side** di setiap endpoint; frontend hanya menyembunyikan UI. Approval action (`*.approve`) terpisah dari `*.update`.

## 5. MODULES

Mengadopsi peta modul master prompt (FASE 2) dengan penyesuaian hasil discovery:
1. **Core** — Organization, User, Role, Permission, Approval, Workflow, Audit Log, Numbering, Settings, Notification.
2. **Master Data** — Customer, Supplier, Employee, Style/Product, Color/Shade, Size/SizeRange, UOM+Conversion, Material (Fabric/Trim/Packaging), Warehouse, Location, Machine, Line, Operation, Defect Library, COA, Currency.
3. **Sales** — BuyerPO, SalesOrder, OrderDetail (style×color×size), Amendment, DeliverySchedule.
4. **Product Development** — Style, Sample, SizeSpec, BOM (versioned), Routing (versioned), TechPack, CostSheet (estimated).
5. **Planning** — MRP (dengan requirement trace), ProductionPlan, CutPlan, Capacity/LineLoading.
6. **Purchasing** — PR, RFQ, Quotation, PO, SupplierInvoice.
7. **Receiving** — GR (roll-level), FabricInspection (4-point/shrinkage/GSM/shade), Putaway, QualityHold release.
8. **Inventory** — Stock, StockLedger, Movement, Reservation, Adjustment, Opname, Lot/Batch/Roll, LeftoverReturn.
9. **Production** — MO, MaterialIssue, WIP, ProductionOutput.
10. **Cutting** — CuttingOrder, Marker, Lay, RollAllocation, Bundle (+barcode), Wastage.
11. **Sewing** — LineOutput, OperatorOutput, Downtime, Efficiency.
12. **Finishing** — FinishingOutput, Repair, Washing (in-house/subcon link).
13. **Quality** — Inspection (inline/endline/final), AQL Sampling Plan, Defect, NCR, Rework, Disposition.
14. **Packing** — PackingInstruction, PackingList, Carton, CartonDetail.
15. **Shipping** — Shipment, Container, CommercialInvoice, ExportDocument.
16. **Subcontracting** — JobWorkOrder, MaterialOut, JobReturn, SubconCost.
17. **Costing** — StandardCost, ActualCost (per MO), Variance, Margin.
18. **Finance** — COA, Journal (operasional + umum), AR, AP, CashBank, PeriodClosing, Valuation, COGS, Financial Reports (full GL), **BEP Report**, ExportInterface (opsional).
19. **Reporting** — per domain + Management dashboard.
20. **Integration** — Barcode/QR, import/export (CSV/Excel).

## 6. BUSINESS PROCESSES

Alur utama + 8 jalur alternatif mengikuti FASE 0 bagian 2. Ketentuan tambahan yang dikunci di FASE 1:

- **BP-01 Order-to-Plan:** SO confirm → MRP run (simpan snapshot kebutuhan) → shortage report → PR otomatis (dapat diedit planner) → PR approve → RFQ/PO.
- **BP-02 Procure-to-Stock:** PO approve → GR (roll-level, status `QUALITY_HOLD`) → Inward QC → PASS: putaway & available / FAIL: claim/return ke supplier (dokumen return).
- **BP-03 Plan-to-Produce:** MO release → **hard reservation** → material issue ke cutting (berdasarkan cut plan/marker) → cutting output → bundling (barcode) → WIP transfer ke sewing.
- **BP-04 Produce-to-FG:** sewing output (per line/hari; per operator/bundle bertahap) → inline QC (rework loop) → finishing → endline/final QC AQL → packing → FG receipt.
- **BP-05 Ship-to-Cash:** shipment plan (validasi toleransi short/excess per buyer) → packing list + commercial invoice → shipment confirm (stok FG berkurang via ledger `SHIPMENT`) → AR invoice → payment.
- **BP-06 Subcon cycle:** JobWorkOrder → material out (lokasi virtual SUBCON, tetap milik perusahaan) → return + QC → biaya jasa masuk actual cost MO terkait.
- **BP-07 Costing cycle:** estimated cost (style, pre-production) → actual collection per MO (material dari ledger issue, labor dari output × upah, overhead = menit SAM terpakai × rate) → variance report per MO/SO.
- **BP-08 Amendment:** perubahan SO → re-run MRP (delta) → adjust PR/PO (cancel/return bila perlu) → adjust cut plan bila belum cutting; **terkunci bila cutting sudah mulai** (OBD-021 default).

## 7. BUSINESS RULES

Aturan yang **dikunci** (dari DECISION_LOG) berkode BR; yang masih terbuka merujuk OBD.

- **BR-001 (OBD-001):** Setiap stok punya `ownership` = COMPANY | BUYER. Stok BUYER tidak ikut valuation & tidak di-nett MRP order lain.
- **BR-002 (OBD-004):** Kain disimpan dual UOM (qty beli + qty pakai/meter) dengan konversi per roll: `meter = kg × 1000 / (GSM × lebar_m)` (atau sebaliknya). Selisih toleransi konversi: ±0.5% per roll (default, configurable).
- **BR-003 (OBD-005):** Fabric wajib roll-level + barcode; trim cukup lot-level.
- **BR-004 (OBD-007):** Semua penerimaan masuk sebagai `QUALITY_HOLD`; available hanya setelah inspection PASS. Trim boleh auto-pass per kategori (configurable).
- **BR-005 (OBD-008):** Valuation = Moving Average per item per company. Ledger menyimpan qty & cost per transaksi.
- **BR-006 (OBD-009):** Hard reservation saat MO release. `available = on_hand − reserved − quality_hold`. Stok tidak boleh negatif (constraint DB).
- **BR-007 (OBD-011):** Output sewing minimal per line per hari; struktur data siap per operator per jam per bundle (scan).
- **BR-008 (OBD-016):** Final inspection memakai AQL ISO 2859-1, General Level II; default 2.5 major / 4.0 minor, per buyer configurable; critical defect = 0 (not allowed).
- **BR-009 (OBD-022/023):** Estimated cost per style (dapat direvisi per SO); actual cost per MO; overhead dialokasikan per menit SAM terpakai (`overhead = Σ(SAM × output) × OH_rate_per_menit`), rate per company per periode.
- **BR-010 (OBD-027):** Nomor dokumen `PREFIX-YYYY-NNNNNN` per company; counter terpisah per prefix+tahun; concurrency-safe; tidak reuse nomor dokumen batal (gap diperbolehkan & tercatat).
- **BR-011 (OBD-029):** Semua tabel transaksi & stok membawa `company_id` (+ `factory_id` bila relevan) sejak awal.
- **BR-012 (OBD-028 default):** Dokumen dengan turunan tidak boleh di-cancel; gunakan reversal/return document.
- **BR-013:** Setiap perubahan stok hanya melalui **Inventory Transaction Service** terpusat (atomic: dokumen + lines + ledger + saldo).
- **BR-014:** Estimated consumption (formula sampling) dan actual consumption (marker) disimpan terpisah di BOM/cutting; costing estimated memakai estimated+wastage%, costing actual memakai realisasi marker + leftover return.
- **BR-104 (DEC-2026-08-14-01):** BEP analysis milik Accounting — `BEP (pcs) = Fixed Cost per periode ÷ (Harga jual per pcs − Variable cost per pcs)`; level factory-wide per bulan + per style; computed report.
- Aturan AQL per buyer, toleransi shipment, batas rework, shade rule → masih `OBD` (default dari FASE 0 dipakai sementara).

## 8. DOCUMENT FLOW

```
Buyer PO → Sales Order ──► (PD: Sample/BOM/Routing/CostSheet) ──► MRP Run
   │                                                              │
   │                                                       Purchase Request
   │                                                              │
   │                                              RFQ → Quotation → Purchase Order
   │                                                              │
   │                                          Goods Receipt (QUALITY_HOLD)
   │                                                              │
   │                                          Fabric/Trim Inspection → Putaway
   │                                                              │
   └── Production Order ──► Reservation ──► Material Issue ──► Cutting Order
                                                              │ (marker, lay, roll, bundle)
                                          WIP Transfer ──► Line Output (Sewing) ──► Finishing
                                                              │
                                          Inspection (inline/endline/FINAL AQL) ──► NCR/Rework?
                                                              │
                                          Packing List ──► FG Receipt ──► Shipment
                                                              │
                                          Commercial Invoice ──► AR Invoice ──► Payment
                                                              │
                                          Actual Cost (per MO) ──► Variance vs Estimated
Cabang: Job Work Order (subcon) keluar-masuk di titik proses terkait.
```

Setiap dokumen menyimpan referensi dokumen sumber (`source_document`, `source_document_line`) untuk traceability dua arah.

## 9. APPROVAL FLOW

Approval engine terpusat (sequential & parallel, rejection, revision, delegation, history). Matrix default:

| Dokumen | Level 1 | Level 2 (kondisional) |
|---|---|---|
| Sales Order | Sales Manager | Management (di atas threshold nilai) |
| Cost Sheet (estimated) | Merchandiser Manager | Management |
| BOM / Routing (versi baru) | PD Manager | — |
| PR | PPIC Manager | — |
| PO | Purchasing Manager | Management (di atas limit nilai, dari approval matrix) |
| Supplier Invoice | Finance (3-way match otomatis; mismatch → manual) | Accounting |
| MO | PPIC Manager | — |
| Cutting Order | Cutting Supervisor | PPIC |
| Stock Adjustment / Opname variance | Warehouse Manager | Finance |
| NCR / Disposition reject | QC Manager | Management (bila scrap di atas threshold) |
| Packing List | QC Final | Shipping |
| Shipment / Commercial Invoice | Shipping Manager | Finance |
| Payment | Finance Manager | Management |
| Period Closing (GL) | Accounting | Finance Manager |

Semua threshold nilai berasal dari **approval matrix (master data)**, bukan kode.

## 10. STATUS FLOW

Baseline dokumen transaksi: `DRAFT → SUBMITTED → APPROVED → IN_PROGRESS → CLOSED`, cabang `REJECTED` (kembali ke draft/revisi) dan `CANCELLED` (hanya sebelum ada dokumen turunan — BR-012).

Status khusus:
- GR line / roll: `QUALITY_HOLD → AVAILABLE | REJECTED_RETURNED`
- MO: `... → RELEASED (reservasi aktif) → CUTTING → SEWING → FINISHING → QC → PACKED → CLOSED`
- Bundle: `CUT → IN_SEWING → SEWN → FINISHED → QC_PASS | REWORK`
- Inspection: `IN_PROGRESS → PASS | FAIL | REWORK | HOLD` dengan disposition `REPAIR | REJECT | SECOND_GRADE | SCRAP`
- Shipment: `PLANNED → STUFFING → DEPARTED → ARRIVED → INVOICED → PAID`
- Stok (derived): on_hand / reserved / quality_hold / in_transit_subcon — bukan status dokumen, melainkan saldo dari ledger.

Status memakai **enum terkontrol per dokumen** (tidak ada string bebas).

## 11. MASTER DATA

Mengacu FASE 0 bagian 5 (24 entitas). Tambahan hasil FASE 1:
- **Approval Matrix** (dokumen, level, role approver, min–max nilai).
- **AQL Config per Buyer** (level inspeksi, AQL major/minor/critical).
- **Packing Instruction** per SO (solid / ratio / mixed).
- **OH Rate per Periode** (untuk BR-009) dan **Cost-per-minute** per line (untuk CM/SMV costing).
- Semua master: kode unik per company, audit columns, soft delete (tidak bisa hapus bila dipakai transaksi).

## 12. TRANSACTION DATA

Mengacu FASE 0 bagian 6. Prinsip:
- Header–detail untuk semua dokumen multi-line; detail style×color×size memakai **matrix line** (satu baris per kombinasi, bukan JSON).
- Ledger & audit log: **append-only**, tidak pernah update/delete; koreksi via entri balik.
- Setiap transaksi stok mencatat `source_document` + `source_document_line` (BR-013).

## 13. REPORTING REQUIREMENTS

Minimum per domain (rinci di FASE 16 master prompt), ditambah hasil discovery:
- **Traceability:** "kenapa butuh N meter?" (MRP trace: SO → BOM → gross → nett), roll → lay → bundle → carton → shipment (two-way).
- **PPIC:** shortage report, line loading vs capacity, order-vs-produced-vs-shipped.
- **Warehouse:** stock balance per item/warehouse/lot/roll, aging, valuation (moving average), akurasi opname.
- **Production:** plan vs actual, efficiency (SAM), WIP per proses, reject & downtime Pareto, leftover & wastage per marker.
- **QC:** defect Pareto per kategori/proses/supplier kain, AQL pass rate per buyer, rework rate.
- **Costing:** cost sheet estimated vs actual per MO/SO, variance (material/labor/OH/subcon), margin per style/buyer.
- **Finance:** AR aging, AP aging, outstanding PO vs GR, COGS per periode, trial balance, P&L, balance sheet dasar (full GL — DEC-03), **BEP analysis per bulan + per style (DEC-2026-08-14-01)**.
- Semua report: filter per company/periode/buyer/style; export Excel/PDF; data dari ledger/view — bukan query langsung tabel transaksi untuk report berat.

## 14. AUDIT REQUIREMENTS

- Audit log: user, waktu, aksi (create/update/submit/approve/reject/cancel), dokumen + line, before→after (field-level), IP, device.
- Ledger & audit log tidak dapat dihapus/diubah siapa pun termasuk admin (hanya Super Admin read; tidak ada UI delete).
- Retensi & partisi per periode (dirancang di FASE 3).
- Setiap approval merekam approver aktual (termasuk delegation).

## 15. SECURITY REQUIREMENTS

- Auth: password hashing modern (Argon2/bcrypt), session/JWT aman, lockout & rate limit login.
- Authorization: RBAC granular, **dicek server-side**; scope data per company.
- Proteksi: CSRF, XSS (output encoding), SQL injection (ORM/prepared statements), validasi file upload (type/size/scan), security headers.
- Audit & monitoring: log akses dokumen sensitif (cost, harga), alarm stok negatif (seharusnya mustahil oleh constraint).
- Prinsip least privilege; permission baru default deny.

## 16. OPEN BUSINESS DECISIONS (SISA)

Terbuka dengan default sementara (detail & rekomendasi di FASE 0 §7), target keputusan per fase implementasi:
OBD-002 (subcon in-scope), OBD-003 (buyer portal), OBD-006 (shade rule), OBD-010 (alokasi shortage), OBD-012 (pemilik SAM & target), OBD-013 (bundle size), OBD-014 (backflush trim), OBD-015 (koreksi output), OBD-017/018 (batas rework & second grade), OBD-019 (toleransi shipment %), OBD-020 (aturan assortment), OBD-021 (batas amendment), OBD-025 (multi-currency operasional), OBD-026 (period lock), OBD-030 (multi-language), OBD-031 (perangkat shop floor), OBD-032 (integrasi existing).

**RESOLVED sejak draft v0.1:** OBD-024 → full GL di ERP (DEC-2026-08-13-03).

---

## PENUTUP

Dokumen ini dikunci **v1.1**. Perubahan berikutnya hanya via OBD/DEC baru + persetujuan pemilik (master prompt aturan 25: kode mengikuti business specification).
