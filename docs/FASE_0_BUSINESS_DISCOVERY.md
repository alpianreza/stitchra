# FASE 0 — BUSINESS DISCOVERY: ERP GARMENT (STITCHRA)

> **Status:** ✅ LOCKED v1.0 — OBD P0 disetujui 13 Agustus 2026 (lihat `DECISION_LOG.md`, DEC-2026-08-13-01). OBD non-P0 tetap terbuka dengan default sementara.
> **Tanggal:** 13 Agustus 2026
> **Penyusun:** AI Agent (peran: Senior ERP Architect + Garment Business Analyst)
> **Sumber riset eksternal:** INTACS ERP+ (intacsindo.com), Infor CloudSuite Fashion (infor.com), Absolute ERP (erpabsolute.com) + riset tambahan: ISO 2859-1 AQL sampling, garment cost sheet (FOB/CM/SMV costing)
> **Aturan:** Tidak ada business rule yang dikunci sebelum ada keputusan. Semua yang belum jelas ditandai `OPEN BUSINESS DECISION` (OBD).

---

## 1. DAFTAR DOMAIN ERP GARMENT

Sintesis dari master prompt + riset industri (INTACS, Infor, Absolute ERP). Struktur ini **belum final** — akan dievaluasi ulang di FASE 2.

### 1.1 Core / Foundation
- Organization (company, factory/branch — persiapan multi-company)
- User, Role, Permission (granular)
- Approval Workflow Engine (centralized, sequential/parallel, delegation)
- Audit Log (append-only, tidak bisa dihapus user biasa)
- Document Numbering (configurable, concurrency-safe)
- System Settings, Notification

### 1.2 Master Data
- Customer / Buyer
- Supplier (fabric, trim, packaging, **subcontractor/job-work**)
- Employee (termasuk operator: skill, line)
- Style / Product + variant axis (color × size)
- Color, Colorway, Shade / Lab-dip
- Size, Size Range, Size Ratio Profile
- UOM + UOM Conversion (per material — kritis untuk kain kg↔meter↔yard)
- Material: Fabric, Thread, Button, Zipper, Label, Hangtag, Polybag, Carton, Accessories, Packaging
- Warehouse (RM, WIP, FG, Trim store), Location / Bin
- Machine, Production Line, Section
- Operation / Operation Bulletin (dengan SMV/SAM)
- Defect Library (kode, kategori, severity)
- Chart of Accounts (jika Finance in-scope)
- Currency & Exchange Rate

### 1.3 Sales / Merchandising
- Buyer PO (Customer PO)
- Sales Order + detail per color/size + size ratio
- Order Amendment (perubahan PO buyer)
- Delivery / Shipment Schedule

### 1.4 Product Development
- Style master & lifecycle
- Sample Management (proto, fit, PP, TOP sample + status approval)
- Size Specification / Measurement Chart
- BOM (fabric + trims, dengan **wastage allowance & shrinkage factor**)
- Routing & Operation (SMV/SAM per operasi)
- Pre-production Costing / Cost Sheet
- Tech Pack

### 1.5 Planning (PPIC)
- Order consolidation / Demand
- MRP (nett requirement: BOM × qty − stok − reservasi − open PO + safety stock)
- Capacity Planning / Line Loading
- Production Planning & Cut Plan

### 1.6 Purchasing
- Purchase Request (PR / Indent)
- RFQ + Supplier Quotation + comparison
- Purchase Order (PO) + approval
- Supplier Invoice (matching ke PO/GR)

### 1.7 Receiving & Inward QC
- Goods Receipt (GR) — roll-level untuk kain
- Fabric Inspection (4-point system, shrinkage test, GSM, shade band)
- Putaway

### 1.8 Inventory / Warehouse
- Stock (on-hand, reserved, available) per item/warehouse/location/lot/roll/color/size/UOM
- Stock Ledger (append-only, sumber kebenaran)
- Reservation, Transfer, Adjustment, Stock Opname
- Lot/Batch/Roll tracking + fabric leftover return

### 1.9 Production
- Production Order (MO)
- Material Reservation & Material Issue
- WIP tracking antar proses

### 1.10 Cutting
- Cutting Order, Marker (efficiency), Lay/Spreading (layer count)
- Fabric Roll Allocation (shade-aware)
- Size Ratio per lay, Color
- Cutting Output, Bundling + Bundle Ticket (barcode)
- Wastage & Leftover (kembali ke inventory)

### 1.11 Sewing
- Line assignment, Operation bulletin
- Operator & Machine assignment
- Target vs Actual (per jam/per hari), Achievement %
- Efficiency berbasis SAM, Downtime log, Reject, Rework, WIP

### 1.12 Finishing
- Trimming, Thread cleaning, Ironing/Pressing
- Washing (in-house atau subcon — OBD)
- Inspection, Repair, Folding

### 1.13 Quality
- Inline / Roving QC, Endline QC, Final Inspection (AQL — OBD)
- Defect record (kategori, severity, qty)
- NCR, Rework/Repair loop, Disposition
- Inspection Report (format per buyer)

### 1.14 Packing
- Packing instruction (solid / assortment / ratio pack)
- Packing List, Carton (nomor, dimensi, GW/NW), Carton Label

### 1.15 Shipping / Export
- Shipment Plan & Delivery Schedule
- Container Loading
- Commercial Invoice, Packing List, Shipping Docs (LC compliance)
- Shipment Status tracking

### 1.16 Subcontracting / Job Work
- Job Work Order (print, bordir, washing, CMT)
- Material Out ke subcon → Return/Reject tracking
- Biaya jasa → masuk costing

### 1.17 Costing
- Estimated (pre-production), Standard, Actual Cost
- Material, Labor (CMT/CMP), Overhead, Subcontract, Wastage, Freight, Duty
- Cost per garment, Variance (estimated vs actual), Margin per style/order

### 1.18 Finance & Accounting
- COA, Journal, AR (invoice buyer), AP (supplier/subcon)
- Cash/Bank, Inventory Valuation, COGS, Revenue, Expense, P&L

### 1.19 Reporting & Dashboard
- Management, PPIC, Warehouse, Production, QC, Purchasing, Sales, Costing, Finance dashboards
- Report per domain (lihat master prompt FASE 16)

### 1.20 Integration
- Barcode/QR scanning (bundle ticket, roll, carton)
- Opsional fase lanjut: CAD/marker software, payroll, e-commerce

---

## 2. PROSES BISNIS END-TO-END

### 2.1 Alur Utama (make-to-order)

```
Buyer PO
  → Sales Order (validasi: style, qty, size ratio, delivery, harga)
  → [jika style baru] Product Development:
      Sample → Size Spec → BOM → Routing (SMV) → Pre-production Costing → Approval
  → PPIC / Planning:
      MRP (BOM × order qty − stok − reserved − open PO + safety stock)
      → Production Plan + Cut Plan (line loading, kapasitas)
  → Purchasing:
      PR → RFQ → Quotation compare → PO (approval) → kirim ke supplier
  → Receiving:
      GR (roll-level utk kain) → Inward QC (4-point/shrinkage/GSM/shade)
      → [PASS] Putaway ke RM warehouse  /  [HOLD/REJECT] claim ke supplier
  → Production Order release
      → Material Reservation → Material Issue ke Cutting
  → Cutting:
      Marker plan → Roll allocation → Spreading/Lay → Cut → Numbering → Bundling (barcode)
      → leftover kain kembali ke inventory; wastage dicatat
  → Sewing:
      Bundle masuk line → output per operasi/jam → inline QC → reject/rework loop
  → Finishing:
      Trim → Iron/Press → (Washing jika ada) → Repair → Fold
  → QC Final (AQL) → PASS / FAIL→Rework
  → Packing (assortment, carton, packing list)
  → FG Warehouse
  → Shipment: container, commercial invoice, dokumen ekspor
  → AR Invoice ke buyer → Payment
  → Actual Costing + Variance vs estimated
  → Reporting
```

### 2.2 Loop & jalur alternatif yang WAJIB didukung
- **Order amendment:** buyer ubah qty/rasio/tanggal setelah produksi berjalan → impact ke MRP, cut plan, PO bahan.
- **Rework/Repair:** defect dari inline/endline/final QC kembali ke proses terkait; batas jumlah rework (OBD).
- **Reject & second grade:** barang reject tidak boleh masuk packing tanpa disposition.
- **Leftover fabric:** sisa roll/lay kembali ke inventory dengan panjang aktual.
- **Subcontract out/in:** bahan keluar ke subcon (stock out sementara), hasil kembali, reject di subcon.
- **Short/excess shipment:** qty kirim ≠ qty order dalam toleransi (OBD).
- **Material substitution:** bahan pengganti saat shortage (butuh approval).
- **Partial shipment & partial receiving.**

### 2.3 Temuan riset yang memperkuat desain
| Sumber | Insight yang diadopsi |
|---|---|
| INTACS ERP+ | Variant management (style×warna×ukuran) adalah inti; BOM harus memuat waste factor & shrinkage; resistensi operator → mulai dari modul berdampak cepat; kualitas master data BOM/routing menentukan kebenaran MRP & costing; subkontrak umum di garment Indonesia |
| Infor CloudSuite Fashion | PLM/Product development terintegrasi dengan sourcing; demand forecast per channel; traceability & sustainability makin diminta buyer; multi-currency & multi-language adalah standar |
| Absolute ERP | Cut plan auto dari PO; inward QC kain (shrinkage, shade, GSM) sebelum release ke produksi; fabric lot tracking end-to-end; AQL report sesuai format buyer; export document automation (PL, CI, LC); hourly output per operator via mobile; job-work tracking keluar/masuk |
| Riset tambahan (ISO 2859-1, garment costing) | AQL: single sampling General Level II, code letter dari lot size, default 2.5 major/4.0 minor, critical=0; Cost sheet FOB = Fabric + Trim + CM; CM = SMV × cost-per-minute; fabric consumption estimated (formula) vs actual (marker) disimpan terpisah |

---

## 3. ACTOR YANG TERLIBAT

| Actor | Peran utama | Modul terkait |
|---|---|---|
| Owner / Management | Approval level tinggi, monitoring KPI | Semua (read), Approval, Dashboard |
| Admin Sistem | User, role, konfigurasi, numbering | Core |
| Sales / Merchandiser | Buyer PO, SO, costing awal, komunikasi buyer | Sales, Costing, PD |
| Product Developer / Pattern / Sample Room | Style, sample, size spec, tech pack | PD |
| IE (Industrial Engineer) | SMV/SAM, operation bulletin, target line | PD, Sewing |
| PPIC / Planner | MRP, production plan, cut plan, material follow-up | Planning, Inventory |
| Purchasing | PR, RFQ, PO, follow-up supplier | Purchasing |
| Warehouse RM | Receiving support, putaway, issue, opname | Inventory |
| Warehouse WIP | Transfer antar proses | Inventory |
| Warehouse FG | Receive FG, prepare shipment | Inventory, Shipping |
| Fabric/QC Inspector (Inward) | Inspeksi kain/trim masuk | Receiving, Quality |
| Cutting Supervisor/Spreader/Cutter/Bundler | Lay, cut, bundle ticket | Cutting |
| Sewing Supervisor / Line Chief | Assign operator, target, output harian | Sewing |
| Operator Jahit | Scan bundle, output | Sewing (UI minimal) |
| Finishing Staff | Trim, iron, fold, repair | Finishing |
| QC Inline / Endline / Final | Inspeksi, catat defect, AQL | Quality |
| Packing Staff | Assortment, carton, packing list | Packing |
| Shipping / Export Staff | Dokumen ekspor, container, status | Shipping |
| Subcontract Coordinator | Job work out/in | Subcontracting |
| Costing / Finance / Accounting | Cost sheet, AR/AP, journal, valuation | Costing, Finance |
| Buyer (eksternal) | Status order & inspection report (portal read-only — OBD) | Sales, Quality |

Role sistem minimal (dari master prompt): Super Admin, Admin, Sales, Merchandiser, Product Development, PPIC, Purchasing, Warehouse, Cutting, Production, QC, Packing, Shipping, Finance, Accounting, Management.

---

## 4. DOKUMEN YANG DIGUNAKAN

| # | Dokumen | Proses | Prefix nomor (usulan) | Approval? |
|---|---|---|---|---|
| 1 | Buyer PO / Customer PO | Sales | (nomor dari buyer) | — |
| 2 | Sales Order | Sales | SO-YYYY-NNNNNN | Ya |
| 3 | Order Amendment | Sales | SOA-YYYY-NNNNNN | Ya |
| 4 | Sample Request & Approval | PD | SMPL-YYYY-NNNNNN | Ya (buyer) |
| 5 | Tech Pack / Spec Sheet | PD | Dokumen style | Ya |
| 6 | BOM (versioned) | PD | Bagian dari style, versi terkontrol | Ya |
| 7 | Routing / Operation Bulletin | PD | Versi terkontrol | Ya |
| 8 | Pre-production Cost Sheet | PD/Costing | COST-YYYY-NNNNNN | Ya |
| 9 | Purchase Request (Indent) | Planning/Purchasing | PR-YYYY-NNNNNN | Ya |
| 10 | RFQ + Quotation Comparison | Purchasing | RFQ-YYYY-NNNNNN | — |
| 11 | Purchase Order | Purchasing | PO-YYYY-NNNNNN | Ya (berjenjang by nilai) |
| 12 | Goods Receipt (GR) | Receiving | GR-YYYY-NNNNNN | — |
| 13 | Fabric/Trim Inspection Report | Inward QC | FQC-YYYY-NNNNNN | Ya |
| 14 | Production Order (MO) | Planning | MO-YYYY-NNNNNN | Ya |
| 15 | Cutting Order + Marker/Lay Sheet | Cutting | CUT-YYYY-NNNNNN | Ya |
| 16 | Material Issue Note | Warehouse→Cutting | MI-YYYY-NNNNNN | — |
| 17 | Bundle Ticket (barcode) | Cutting→Sewing | (barcode dari CUT) | — |
| 18 | WIP Transfer Note | Antar proses | WIP-YYYY-NNNNNN | — |
| 19 | Line Output / Production Report | Sewing | OUT-YYYY-NNNNNN | — |
| 20 | Downtime Log | Sewing | Bagian dari output report | Ya (supervisor) |
| 21 | Inspection Report (inline/endline/final AQL) | QC | QC-YYYY-NNNNNN | Ya |
| 22 | NCR (Non-Conformance Report) | QC | NCR-YYYY-NNNNNN | Ya |
| 23 | Rework Note | QC/Production | RW-YYYY-NNNNNN | — |
| 24 | Job Work Order (subcon) | Subcontracting | JW-YYYY-NNNNNN | Ya |
| 25 | Packing List | Packing | PKL-YYYY-NNNNNN | Ya |
| 26 | Shipment / Shipping Instruction | Shipping | SHP-YYYY-NNNNNN | Ya |
| 27 | Commercial Invoice | Shipping/Finance | INV-YYYY-NNNNNN | Ya |
| 28 | AR Invoice / Supplier Invoice | Finance | AR-/AP-YYYY-NNNNNN | Ya |
| 29 | Payment Voucher / Journal Voucher | Finance | PV-/JV-YYYY-NNNNNN | Ya |
| 30 | Stock Adjustment / Opname Sheet | Inventory | ADJ-/OPN-YYYY-NNNNNN | Ya |

Semua dokumen transaksi: nomor unik, concurrency-safe, configurable, tidak bergantung pada ID database (FASE 4).

---

## 5. MASTER DATA

| Master Data | Atribut kunci (draft) | Catatan kritis |
|---|---|---|
| Company / Factory | kode, nama, alamat, mata uang dasar | Siapkan sejak awal walau implement 1 company |
| Customer/Buyer | kode, brand, negara, currency, payment term, incoterm, toleransi shipment | Satu buyer bisa punya banyak brand/divisi |
| Supplier | tipe (fabric/trim/packaging/subcon), lead time, currency, term | Subcon = supplier bertipe job-work |
| Employee | NIK, nama, section, line, skill, status operator | Basis insentif/efisiensi per operator |
| Style/Style Master | style no (internal), buyer style ref, season, kategori (woven/knit), product group, lifecycle status | Style ≠ SKU; SKU = style×color×size |
| Color / Colorway | kode, nama buyer, lab-dip ref, shade group | Shade band untuk kain (OBD) |
| Size & Size Range | kode size, urutan, range (mis. S–XXL) | Size ratio profile per buyer |
| UOM & Conversion | pcs, meter, yard, kg, cone, gross; konversi per material | **Kritis:** kain dibeli per kg tapi dipakai per meter → konversi GSM×width per lot (OBD) |
| Material — Fabric | komposisi, konstruksi, GSM, lebar, shrinkage std, UOM beli & pakai | Consumption dari BOM + marker actual |
| Material — Trim/Accessories | benang, kancing, zipper, label, hangtag, dll. | Consumption per pcs + allowance |
| Material — Packaging | polybag, carton (dimensi std) | Dipakai di packing |
| Warehouse | tipe (RM/WIP/FG/Trim/Subcon-in-transit) | Multi-warehouse ready |
| Location/Bin | per warehouse | Opsional per gudang (OBD) |
| Machine | tipe (single needle, overlock, bartack, dst.), line | Untuk operation bulletin & downtime |
| Production Line / Section | kapasitas, jumlah operator std | Basis line loading |
| Operation | kode, nama, tipe mesin, SMV/SAM, grade | Versioned per style via routing |
| Defect Library | kode, nama, kategori (fabric/workmanship/dll), severity (critical/major/minor) | Standar AQL-ready |
| COA | kode akun, tipe, normal balance | Jika Finance in-scope |
| Currency & Rate | kode, rate, tanggal | Multi-currency ready (OBD) |
| Doc Numbering Config | prefix, periode reset, digit | Per company |
| Approval Matrix | dokumen, level, approver, limit nilai | Configurable, bukan hard-code |

---

## 6. TRANSACTION DATA

| Domain | Transaksi utama | Sifat volume |
|---|---|---|
| Sales | Sales Order, SO detail (style×color×size), amendment, delivery schedule | Rendah–sedang |
| PD | Sample record, BOM version, routing version, cost sheet | Rendah |
| Planning | MRP run (traceable), production plan, cut plan | Sedang |
| Purchasing | PR, RFQ, quotation, PO + lines, supplier invoice | Sedang |
| Receiving | GR + lines (**per roll untuk kain**), inspection result | Sedang–tinggi |
| Inventory | Stock ledger entries (semua movement), reservation, transfer, adjustment, opname | **Sangat tinggi — append-only** |
| Cutting | Cutting order, lay detail, roll usage, bundle header/detail | Tinggi (bundle bisa ribuan/order) |
| Sewing | Line output (per jam/hari, per operator/bundle), downtime, WIP movement | **Sangat tinggi (scan-based)** |
| Finishing | Output, repair record | Tinggi |
| QC | Inspection header + defect detail, NCR, rework | Tinggi |
| Packing | Packing list, carton detail (isi per karton per SKU) | Tinggi |
| Shipping | Shipment, container, dokumen | Rendah–sedang |
| Subcontracting | Job work order, material out, return | Sedang |
| Costing | Actual cost collection per MO, variance | Sedang |
| Finance | Journal, AR, AP, payment | Sedang |
| Core | Audit log (semua transaksi penting) | **Sangat tinggi — append-only, partisi/arsip** |

---

## 7. BUSINESS RULES YANG HARUS DIPUTUSKAN (OPEN BUSINESS DECISIONS)

> Format: **OBD-NNN — topik.** Konteks. **OPSI A / OPSI B** + konsekuensi. **Rekomendasi.** `DECISION REQUIRED`.
> Yang bertanda **[P0]** wajib diputuskan sebelum desain database (FASE 3).
> **UPDATE 13 Agu 2026:** seluruh OBD [P0] telah DIPUTUSKAN (DEC-2026-08-13-01 di `DECISION_LOG.md`); kolom status di bawah menandai hasilnya. OBD non-P0 tetap terbuka dengan default = rekomendasi.

### Kelompok A — Model Bisnis

**OBD-001 [P0] — Model bisnis: CMT, FOB, atau keduanya?** ✅ **DIPUTUSKAN: desain siap keduanya (flag ownership stok COMPANY|BUYER), implementasi awal FOB.**
CMT = buyer supply bahan, pabrik jual jasa. FOB = pabrik beli bahan & jual barang jadi.
- OPSI A: FOB saja → material selalu milik pabrik; costing penuh.
- OPSI B: Keduanya → perlu konsep *buyer-owned/consignment stock* (stok kain milik buyer, tidak boleh tercampur valuasinya).
- Konsekuensi B: tabel stok butuh flag kepemilikan; MRP tidak boleh netting stok buyer ke order buyer lain.

**OBD-002 — Subcontracting in-scope sejak awal?** ⏳ Default: in-scope di desain, implement fase 6–7.
Proses umum disubkontrakkan: printing, bordir, washing, kadang CMT penuh.
- OPSI A: in-scope → perlu modul Job Work (material out, tracking, return, biaya jasa → costing).
- OPSI B: nanti → desain costing & inventory tetap sisakan hook (lokasi "subcon in-transit").

**OBD-003 — Buyer portal / akses eksternal?** ⏳ Default: fase lanjut.
(buyer lihat status order & inspection report) Konsekuensi: arsitektur multi-tenant read-only, security tambahan.

### Kelompok B — Material & Inventory

**OBD-004 [P0] — Satuan kain: kg, meter, atau yard?** ✅ **DIPUTUSKAN: dual UOM tersimpan (qty beli + meter) dengan konversi GSM×lebar per roll/lot + toleransi selisih (default ±0,5%).**
Praktik umum: beli per kg (knit) atau per meter/yard (woven), konsumsi BOM per meter.
- OPSI A: satu UOM kebenaran (meter), konversi dari kg via GSM×lebar **per lot** → akurat tapi butuh data GSM/width per lot saat GR.
- OPSI B: dual UOM tersimpan (kg + meter) dengan konversi per lot → fleksibel, risiko selisih pembulatan.

**OBD-005 [P0] — Tracking kain sampai level roll atau cukup lot?** ✅ **DIPUTUSKAN: roll-level untuk fabric (barcode per roll), lot-level untuk trim.**
- OPSI A (roll): traceability penuh (4-point, shade, leftover per roll) — wajib untuk ekspor besar; effort input tinggi (barcode per roll).
- OPSI B (lot): lebih ringan, kehilangan detail shade/leftover per roll.

**OBD-006 — Shade band control saat cutting?** ⏳ Default: rule configurable per buyer.
Rule umum: satu lay tidak boleh campur shade berbeda untuk panel yang sama. Konsekuensi: alokasi roll ke lay harus validasi shade group.

**OBD-007 [P0] — Kapan stok diakui tersedia: saat GR atau setelah lulus Inward QC?** ✅ **DIPUTUSKAN: OPSI B — stok masuk `QUALITY_HOLD`, available setelah inspeksi PASS (trim boleh auto-pass per kategori).**
- OPSI A (GR langsung available): cepat, tapi bahan belum tentu lolos uji.
- OPSI B (status `QUALITY_HOLD` sampai inspeksi PASS): aman, butuh status stok tambahan.

**OBD-008 [P0] — Metode inventory valuation (Finance)?** ✅ **DIPUTUSKAN: Moving Average; ledger menyimpan cost per transaksi agar migrasi metode memungkinkan.**
- OPSI A: Moving Average — sederhana, umum di manufaktur Indonesia.
- OPSI B: FIFO per lot — akurat untuk kain per lot, lebih kompleks.
- OPSI C: Standard Cost + variance — cocok jika costing mature.

**OBD-009 [P0] — Stock reservation: kapan dan seberapa ketat?** ✅ **DIPUTUSKAN: hard reservation saat MO release; shortage report sejak SO confirm.**
- OPSI A: hard reservation (stok terkunci eksklusif untuk MO) — aman, bisa menimbulkan deadlock stok.
- OPSI B: soft reservation (indikatif, first-come saat issue) — fleksibel, risiko shortage saat issue.

**OBD-010 — Alokasi bahan saat shortage antar order?** ⏳ Default: manual planner dengan rekomendasi sistem (by delivery date).

### Kelompok C — Produksi

**OBD-011 [P0] — Granularitas pencatatan sewing: per operator per jam, atau per line per hari?** ✅ **DIPUTUSKAN: desain mendukung per operator/jam (scan bundle); implementasi bertahap mulai per line/hari.**
- OPSI A (per operator/jam, scan bundle): data kaya (efisiensi per operator, insentif piece-rate) — butuh perangkat scan & disiplin.
- OPSI B (per line/hari): ringan, kehilangan detail insentif.

**OBD-012 — Formula efisiensi & target.** ⏳ Default: formula SAM configurable & versioned, pemilik IE.
Efisiensi = (SAM × output) / (manpower × menit kerja) × 100. Siapa pemilik SAM (IE?), apakah target per style per line configurable?

**OBD-013 — Bundle size & ticket.** ⏳ Default: bundle size per style configurable, barcode per bundle. Ukuran bundle standar (mis. 10–20 pcs)? Satu bundle satu operasi scan?

**OBD-014 — Backflush atau issue aktual?** ⏳ Default: aktual untuk fabric, backflush boleh untuk trim murah (configurable per material class).
- OPSI A (aktual per marker/issue): akurat untuk kain (konsumsi nyata dari lay).
- OPSI B (backflush dari output × BOM): ringan tapi menyembunyikan waste.

**OBD-015 — Cut-off output harian & koreksi.** ⏳ Default: koreksi hanya via adjustment ber-approval + audit.

### Kelompok D — Quality

**OBD-016 [P0] — Pakai AQL? Level berapa?** ✅ **DIPUTUSKAN: AQL engine ISO 2859-1 General Level II, default 2.5 major / 4.0 minor, critical = 0; per buyer configurable.**
Buyer ekspor umumnya minta AQL 2.5 (major) / 4.0 (minor) — berbeda per buyer.
- Konsekuensi: perlu tabel sampling plan (lot size → sample size → accept/reject number) + AQL per buyer configurable.

**OBD-017 — Alur reject & batas rework.** ⏳ Terbuka: berapa kali satu pcs/bundle boleh rework sebelum jadi reject final/second grade? Siapa disposition (QC manager)?

**OBD-018 — Second grade / reject sale?** ⏳ Terbuka: apakah barang reject dijual (butuh stok & transaksi tersendiri)?

### Kelompok E — Sales, Packing, Shipment

**OBD-019 — Toleransi qty shipment (short/excess %)?** ⏳ Default: field toleransi di buyer/SO (umum ekspor ±3–5%), validasi saat packing & shipment.

**OBD-020 — Aturan assortment packing.** ⏳ Default: packing instruction master per SO (solid / ratio pack / mixed).

**OBD-021 — Order amendment setelah produksi jalan.** ⏳ Default: terkunci setelah cutting dimulai; sebelum itu via amendment + MRP delta.

### Kelompok F — Costing & Finance

**OBD-022 [P0] — Level costing: per style, per SO, atau per MO?** ✅ **DIPUTUSKAN: estimated per style (revisi per SO), actual per MO, laporan per SO.**

**OBD-023 [P0] — Basis alokasi overhead.** ✅ **DIPUTUSKAN: per menit SAM terpakai (OPSI A), configurable; rate per company per periode.**
- OPSI A: per menit SAM terpakai (umum di garment) — adil antar style.
- OPSI B: per pcs — sederhana, bias terhadap style kompleks.
- OPSI C: per line-day — cocok jika line dedicated.

**OBD-024 [P0] — Scope Finance: full accounting (journal, GL) atau cukup AR/AP + costing?** ✅ **DIPUTUSKAN SEMENTARA: desain hook integrasi + AR/AP/journal operasional di ERP; keputusan akhir full GL menunggu info software akuntansi existing (pertanyaan no. 27).**
- OPSI A: full GL di ERP — beban besar, perlu COA, periode, closing.
- OPSI B: AR/AP/costing di ERP, GL di software akuntansi existing (integrasi/ekspor).

**OBD-025 — Multi-currency sejak awal?** ⏳ Default: schema multi-currency sejak awal; dokumen menyimpan currency + rate.

**OBD-026 — Periode akuntansi & lock transaksi?** ⏳ Default: period lock + adjustment ber-approval.

### Kelompok G — Sistem

**OBD-027 [P0] — Document numbering: reset per tahun atau bulan? Per company/branch?** ✅ **DIPUTUSKAN: per company + prefix + tahun, counter terpisah, concurrency-safe; nomor batal tidak reuse.**

**OBD-028 — Status flow standar dokumen.** ⏳ Default dipakai: baseline `DRAFT → SUBMITTED → APPROVED → (IN_PROGRESS) → CLOSED` + cabang `REJECTED / CANCELLED`; cancel terkunci jika ada dokumen turunan (pakai reversal/return).

**OBD-029 — Multi-company/multi-factory: dibangun siap sejak awal atau nanti?** ✅ **DIPUTUSKAN: schema siap (company_id/factory_id di semua tabel transaksi & stok), operasional 1 company dulu.**

**OBD-030 — Multi-language (ID/EN)?** ⏳ Default: UI i18n-ready sejak awal; label master data satu bahasa + terjemahan opsional.

**OBD-031 — Perangkat shop floor.** ⏳ Terbuka: barcode scanner / tablet / mobile? Menentukan desain UI input produksi.

**OBD-032 — Integrasi existing.** ⏳ Terbuka: software CAD/marker, payroll, akuntansi yang sudah dipakai? Ekspor/impor apa saja?

---

## 8. RISIKO DESAIN

| # | Risiko | Dampak | Mitigasi |
|---|---|---|---|
| 1 | Salah model variant (style×color×size) | Seluruh inventory, BOM, order salah | Kunci model variant di FASE 1; prototype data nyata |
| 2 | Konversi UOM kain (kg↔m) dengan GSM/lebar per lot | Selisih stok sistematis, costing melenceng | Konversi per roll/lot tersimpan + toleransi + opname rutin |
| 3 | BOM/routing master tidak akurat | MRP & costing salah (garbage in → garbage out) | Validasi BOM ber-approval + versioning + pilot 3–5 style |
| 4 | Scope creep (Finance, HR, payroll, portal buyer) | Proyek tidak pernah selesai | Roadmap berfase (FASE 22 master prompt) disiplin |
| 5 | Resistensi operator di shop floor | Data produksi tidak masuk sistem | UI scan-first, minim ketik, pilot 1 line, insentif jelas |
| 6 | Inventory real-time vs performa | Lambat saat volume ledger besar | Ledger append-only + tabel summary + index benar |
| 7 | Race condition reservasi/issue stok | Stok negatif / double issue | Transaction boundary + row locking + constraint stok ≥ 0 |
| 8 | Volume audit log & ledger besar | DB membengkak | Partisi per periode, arsip, retensi terdokumentasi |
| 9 | Approval di-hardcode per modul | Sulit audit & ubah | Workflow engine terpusat (FASE 12) |
| 10 | Nomor dokumen duplikat saat concurrent | Integritas dokumen rusak | Counter table dengan locking/sequence DB |
| 11 | Perubahan requirement saat blueprint berjalan | Rework desain | Dokumen ini versioned; perubahan lewat OBD baru |
| 12 | Migrasi data legacy (Excel) kotor | Go-live gagal | Template migrasi + cleansing + dry-run |
| 13 | Security: permission hanya di frontend | Bypass via API | Server-side enforcement di setiap endpoint + test permission |
| 14 | Overhead costing tidak realistis | Margin salah ambil keputusan | OBD-023 diputuskan dengan data historis |
| 15 | Subcon in-transit stock tidak dilacak | Bahan hilang di vendor | Lokasi virtual "SUBCON" + aging report |

---

## 9. PERTANYAAN YANG HARUS DIJAWAB SEBELUM DATABASE DIBUAT

### Profil Bisnis
1. Berapa pabrik/factory, line jahit, dan operator saat ini? Target 2–3 tahun?
2. Produk utama: woven, knit, atau keduanya? Kategori (kemeja, kaos, celana, jaket)?
3. Buyer domestik, ekspor, atau keduanya? Negara tujuan utama? Mata uang invoice?
4. Rata-rata order per bulan & qty per order? Order terbesar/terkecil?
5. Model: CMT, FOB, atau campur? ✅ (OBD-001: siap keduanya, FOB dulu)
6. Apakah ada proses subcon (print/bordir/washing)? Seberapa sering? (OBD-002)
7. Apakah buyer mensyaratkan AQL & format report tertentu? ✅ (OBD-016: AQL 2.5/4.0 configurable)
8. Apakah buyer boleh akses status order (portal)? (OBD-003)

### Material & Inventory
9. Kain dibeli per kg, meter, atau yard? Apakah GSM & lebar selalu diketahui saat beli? ✅ (OBD-004: dual UOM per roll)
10. Perlukah tracking per roll (dengan barcode)? ✅ (OBD-005: roll-level fabric)
11. Apakah gudang sudah pakai location/bin? Berapa gudang fisik?
12. Apakah ada inspeksi kain masuk (4-point/shrinkage) saat ini? Siapa yang melakukan? ✅ (OBD-007: quality hold)
13. Apakah stok trim juga perlu lot tracking, atau cukup agregat? ✅ (OBD-005: trim lot-level)
14. Metode penilaian persediaan yang dipakai akuntansi sekarang? ✅ (OBD-008: moving average)
15. Safety stock per bahan: ada kebijakannya?

### Produksi
16. Bagaimana output jahit dicatat hari ini (manual, per jam)? Ada rencana scan barcode? (OBD-011✅, 031)
17. Apakah SMV/SAM per operasi sudah ada datanya? Siapa yang maintain? (OBD-012)
18. Ukuran bundle & apakah bundle ticket sudah dipakai? (OBD-013)
19. Bagaimana perhitungan upah operator: bulanan, piece-rate, atau insentif campuran? (memengaruhi kebutuhan data per operator)
20. Apakah ada washing/finishing khusus (in-house/subcon)?
21. Bagaimana penanganan reject & rework saat ini? (OBD-017, 018)
22. Cut plan dibuat siapa — PPIC atau cutting supervisor?

### Sales–Shipment
23. Toleransi short/excess shipment per buyer? (OBD-019)
24. Bentuk packing yang umum: solid, ratio pack, mixed? Instruksi dari siapa? (OBD-020)
25. Seberapa sering order amendment & apa batasnya? (OBD-021)
26. Dokumen ekspor apa yang wajib (PL, CI, COO, LC)?

### Finance & Costing
27. **Software akuntansi yang dipakai sekarang? ERP harus full GL atau integrasi? (OBD-024 — masih menunggu jawaban ini)**
28. Struktur costing yang dipakai: bagaimana overhead dialokasikan saat ini? ✅ (OBD-023: per menit SAM)
29. Costing dibuat per style atau per order? ✅ (OBD-022)
30. Kebijakan lock periode & koreksi transaksi? (OBD-026)

### Sistem & Teknis
31. Berapa user concurrent? Role apa saja yang aktif?
32. Perangkat di pabrik: PC, tablet, scanner, printer barcode? (OBD-031)
33. Preferensi deployment: cloud atau on-premise? (kondisi internet pabrik?)
34. Bahasa UI: Indonesia, Inggris, atau keduanya? (OBD-030)
35. Data legacy apa yang harus dimigrasi (master, stok awal, open PO/SO)?
36. SLA uptime & jam operasional sistem (24/7 atau jam pabrik)?

---

## 10. KESIMPULAN & STATUS

FASE 0 ini memetakan 20 domain, alur end-to-end + 8 jalur alternatif, 18 aktor, 30 dokumen, 24 master data, dan 32 Open Business Decisions.

**Status 13 Agustus 2026:**
- ✅ Seluruh OBD P0 DIPUTUSKAN (DEC-2026-08-13-01) → FASE 0 LOCKED v1.0.
- ✅ FASE 1 (`ERP_GARMENT_BUSINESS_SPECIFICATION.md`) telah dibuat (DRAFT v0.1, menunggu review).
- ⏳ OBD non-P0 berjalan dengan default sementara; pertanyaan operasional (no. 1–4, 6, 8, 11, 15–22, 24–27, 31–36) dapat dijawab bertahap — jawaban akan mempertajam blueprint database (FASE 3) tanpa menghalangi FASE 2.

---
*Dokumen ini adalah sumber kebenaran sementara untuk discovery. Setiap perubahan business rule harus melalui OBD baru dan persetujuan, sesuai master prompt aturan ke-25: kode mengikuti business specification, bukan sebaliknya.*
