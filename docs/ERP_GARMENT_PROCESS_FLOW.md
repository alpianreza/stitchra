# ERP GARMENT — PROCESS FLOW (detail per proses)

> **Status:** DRAFT v0.2 — koreksi sitasi BR (F-2, F-3); menunggu approval kunci v1.0
> **Tanggal:** 13 Agustus 2026
> **Dasar:** FASE 0 v1.0 (LOCKED), FASE 1 Business Specification v0.1, MODULE_MAP v0.2, DEC-2026-08-13-01 & DEC-2026-08-13-02
> **Notasi:** `[DOK]` = dokumen transaksi; `(STATUS)` = status yang di-set; `⇒` = efek stok via Inventory Transaction Service (BR-013).

---

## PF-01 ORDER-TO-PLAN (Sales → MRP)

1. Merchandiser input Buyer PO → buat `[SO]` (DRAFT): style, colorway, size ratio/matrix qty, harga, delivery date, toleransi shipment (dari master buyer, OBD-019).
2. Submit → approval Sales Manager (→ Management bila di atas threshold) → `(APPROVED/CONFIRMED)`.
3. Jika style belum punya BOM/Routing approved → blokir ke PD (PF-02) sebelum planning.
4. PPIC menjalankan **MRP Run** terhadap SO confirmed:
   - Gross requirement = Σ (BOM line qty × SO qty per colorway) × (1 + wastage% + shrinkage%).
   - Nett = gross − on_hand(AVAILABLE) − open PO qty + safety stock. **Stok BUYER-owned tidak ikut netting untuk order company, dan sebaliknya** (BR-001).
   - Hasil: `[MRP_RUN]` + requirement lines + **trace** (SO line → BOM line → gross → nett) — user bisa klik "kenapa butuh N?".
5. Shortage → sistem usulkan `[PR]` otomatis (dapat diedit PPIC) → submit → approval → lanjut PF-03.
6. PPIC menyusun **production plan & line loading** → draft `[MO]` per SO/style (qty per color-size mengikuti matrix SO).

**Amendment (PF-01a):** perubahan SO (qty/rasio/tanggal) → `(AMENDED)` → MRP delta run → adjust PR/PO/MO. **Terkunci bila cutting sudah mulai** (OBD-021 default).

---

## PF-02 PRODUCT DEVELOPMENT (Style → BOM → Costing)

1. PD buat/ubah **style**: spec, measurement chart (per size), tech pack.
2. **Sample cycle**: proto → fit → PP → TOP; tiap stage `[SMPL]` + status approval buyer; revisi membuat versi baru.
3. **BOM** (versioned): lines = material (fabric per colorway; trim per style), qty per pcs, wastage%, shrinkage%, UOM pakai. Submit → approval PD Manager → `(APPROVED)`; perubahan pasca-approval = versi baru.
4. **Routing** (versioned): urutan operasi + SMV per operasi + tipe mesin → total SAM style. Approval PD Manager.
5. **Pre-production Cost Sheet** `[COST]` (estimated, per style):
   - Fabric cost = consumption est × harga terakhir/quotation.
   - Trim cost = Σ trim lines.
   - CM = total SAM × cost-per-minute line (master.finance).
   - Overhead = total SAM × OH rate per menit (BR-009).
   - FOB = fabric + trim + CM + OH (+ subcon est bila ada) → margin → harga penawaran.
   - Approval Merchandiser Manager (→ Management). Cost sheet approved menjadi **standard cost** untuk variance.

---

## PF-03 PROCURE-TO-STOCK (PR → PO → GR → Inward QC)

1. `[PR]` (dari MRP/manual) → approval PPIC Manager.
2. (Opsional) `[RFQ]` → quotation beberapa supplier → comparison → pilih.
3. `[PO]` ke supplier: item, qty (UOM beli), harga, currency, delivery date, term. Approval berjenjang by nilai (approval matrix) → `(APPROVED)` → kirim ke supplier.
4. Barang datang → `[GR]`:
   - **Fabric: satu line per roll** (BR-003/052): roll no, batch/lot, shade (bila ada), qty beli (kg/yard) **dan** panjang meter aktual → konversi per roll disimpan (BR-002).
   - Semua line masuk dengan `(QUALITY_HOLD)` (BR-004) ⇒ ledger: `PURCHASE_RECEIPT` (+qty ke status hold, +cost).
   - Update `po_lines.received_qty` (partial allowed).
5. **Inward QC** `[FQC]` per GR line:
   - Fabric: 4-point inspection (defect points per 100 yd²), shrinkage test, GSM aktual, shade banding.
   - Hasil: PASS ⇒ release hold (ledger: `QUALITY_RELEASE`, saldo pindah hold→available, putaway ke location); FAIL → `[supplier return]` ⇒ ledger `PURCHASE_RETURN` + claim.
   - Partial pass dimungkinkan per roll.
6. Supplier invoice → **3-way match** (PO vs GR vs invoice; toleransi harga/qty dari matrix) → approve → AP (PF-10).

---

## PF-04 PLAN-TO-CUT (MO release → Material Issue)

1. `[MO]` approve PPIC Manager → `(RELEASED)` ⇒ **hard reservation** (BR-006): reserved_qty naik untuk semua BOM lines; bila available kurang → release **gagal** dengan shortage list (OBD-010: alokasi manual planner).
2. PPIC buat **cut plan**: berapa lay, size ratio per lay, target marker length.
3. `[CUT]` cutting order dari cut plan → approval Cutting Supervisor + PPIC.
4. **Material issue** `[MI]` dari RM warehouse ke cutting (aktual per roll untuk fabric — OBD-014):
   - Pilih roll (system usulkan FIFO/shade-match, OBD-006) ⇒ ledger: `MATERIAL_ISSUE` (RM berkurang, WIP-cutting bertambah; reservation berkurang sesuai issue).

---

## PF-05 CUTTING (Lay → Bundle)

1. **Spreading/Lay**: per lay catat roll(s) terpakai, layer count, panjang aktual, shade per roll (validasi satu lay satu shade group bila rule aktif).
2. **Marker**: catat marker length & efficiency = (panjang pola terpakai / panjang lay) — efficiency di bawah threshold (setting) → warning ke PPIC.
3. **Cut output**: qty per size/color dari lay → `(CUT)`.
4. **Bundling**: potongan dikelompokkan per bundle (size per style, OBD-013) → tiap bundle dapat **barcode ticket** (bundle id + CUT + style/color/size + qty).
5. **Leftover & wastage** per roll: `panjang_awal − Σ pemakaian lay = leftover` → leftover return ⇒ ledger `PRODUCTION_RETURN` ke RM (status available/hold sesuai QC); wastage (end bits) dicatat → masuk actual cost (BR-014/BR-042).
6. WIP transfer bundle → sewing `[WIP]` ⇒ ledger: pindah WIP-cutting → WIP-sewing.

---

## PF-06 SEWING & FINISHING

1. Supervisor assign bundle → line (+ operator/machine per operasi, bertahap sesuai OBD-011).
2. **Output** `[OUT]` per line per hari (minimal): qty per bundle/operasi; target dari routing (SAM × manpower × jam) → achievement %.
3. **Scan-based (fase lanjut)**: operator scan bundle ticket per operasi → output per operator/jam → efisiensi = (SAM×output)/(manpower×menit kerja)×100 (OBD-012).
4. **Downtime** dicatat per kategori (mesin, bahan kurang, listrik, setting) → approval supervisor (OBD-015).
5. **Inline/roving QC**: defect dicatat per defect code → pcs jelek → rework (kembali ke operasi terkait; counter rework++, batas OBD-017) atau reject → NCR (PF-07).
6. Bundle selesai semua operasi → `(SEWN)` → WIP transfer ke **finishing**: trimming, pressing, (washing — in-house `[wash record]` atau subcon PF-08), repair, folding → endline QC → `(FINISHED)`.

---

## PF-07 QUALITY (Final Inspection & NCR)

1. **Final inspection** `[QC]` atas lot (per MO/color atau per shipment lot):
   - Engine AQL (BR-008): lot size → code letter (ISO 2859-1 G-II) → sample size; Ac/Re untuk major (default 2.5) & minor (default 4.0); critical = 0.
   - Defect dicatat per code/category/severity + qty.
   - Hasil: PASS → `(QC_PASS)` (layak packing); FAIL → NCR.
2. `[NCR]` → disposition oleh QC Manager: **REWORK** (kembali ke sewing/finishing; setelah rework wajib re-inspection), **REPAIR**, **REJECT/SECOND_GRADE/SCRAP** (OBD-017/018; scrap di atas threshold → approval Management).
3. Inspection report tersedia dalam **format per buyer** (dari AQL config).

---

## PF-08 SUBCONTRACTING (Job Work)

1. `[JW]` Job Work Order ke supplier tipe subcon (proses: print/bordir/washing/CMT), refer ke MO/operasi → approval.
2. **Material out**: panel/bundle/bahan dikirim ⇒ ledger `TRANSFER` ke lokasi virtual **SUBCON** (kepemilikan tetap company; tidak mengubah valuation).
3. **Return**: barang kembali → QC → qty bagus ⇒ `TRANSFER` balik ke WIP; qty reject → NCR + claim subcon.
4. Invoice jasa subcon → match ke JW → **biaya masuk actual cost MO** (BR-009) + AP (PF-10).
5. Report: outstanding di subcon + aging (mitigasi risiko bahan hilang).

---

## PF-09 PACK-TO-SHIP (Packing → Shipment → AR)

1. **Packing instruction** dari SO (solid/ratio/mixed, OBD-020).
2. `[PKL]` packing: scan/assign pcs lulus QC ke carton (no carton, dimensi, GW/NW); isi carton per SKU divalidasi terhadap instruction ⇒ ledger: WIP-finishing → **FG** (`PRODUCTION_RECEIPT` saat FG masuk gudang FG).
3. **Shipment plan** `[SHP]`: pilih PKL/cartons, container, ETA/ETD; validasi **toleransi short/excess** vs SO (OBD-019) → approval Shipping Manager → Finance.
4. Dokumen: **Commercial Invoice `[INV]`** + packing list final + dokumen ekspor (LC-ready).
5. Shipment confirm `(DEPARTED)` ⇒ ledger `SHIPMENT` (FG berkurang, +COGS basis) → draft **AR invoice**.
6. Status lanjut: ARRIVED → INVOICED → PAID (payment di PF-10).

---

## PF-10 FINANCE (AR/AP/Journal)

1. **AR**: invoice dari CI/shipment → aging per buyer → payment receipt (currency + rate; selisih kurs ke journal).
2. **AP**: dari supplier invoice (PO match) & invoice subcon → payment → aging per supplier.
3. **Journal operasional** (otomatis dari event): GR (inventory vs accrued AP), invoice match (accrual → AP), shipment (COGS vs inventory), payment, adjustment stok, variance opname.
4. **Period lock** (OBD-026): transaksi periode terkunci tidak bisa dibuat/diubah; koreksi via adjustment periode berjalan.
5. **Ekspor journal** ke software akuntansi existing (OBD-024) via integration jobs (CSV/Excel).

---

## PF-11 COSTING (Estimated vs Actual)

1. **Estimated**: dari PF-02 (cost sheet approved = standard).
2. **Actual per MO** (BR-009):
   - Material = Σ ledger `MATERIAL_ISSUE` cost − leftover return + wastage value.
   - Labor = Σ output × rate (piece-rate atau line cost; formula configurable).
   - Overhead = Σ (SAM × output) × OH rate per menit periode berjalan.
   - Subcon = Σ biaya JW terkait MO.
3. **Variance report** per MO/SO: estimated vs actual per komponen (material/labor/OH/subcon) → margin aktual per style/buyer.

---

## PF-12 INVENTORY OPERATIONS

1. **Transfer** antar warehouse/location `[WIP/transfer]` ⇒ ledger `TRANSFER_OUT`+`TRANSFER_IN`.
2. **Adjustment** `[ADJ]` (approval Warehouse Manager → Finance) ⇒ ledger `ADJUSTMENT` (+/− qty & cost).
3. **Opname** `[OPN]`: freeze saldo sistem → input count fisik (per roll untuk fabric) → variance → approval → ledger `OPNAME_ADJUSTMENT`; report akurasi stok.
4. Semua saldo derived: `on_hand`, `reserved`, `quality_hold`, `in_transit_subcon`; **available = on_hand − reserved − quality_hold** (BR-006); stok tidak boleh negatif (constraint).

---

## MATRIKS TRACEABILITY (contoh ujung-ke-ujung)

`Buyer PO line` → `SO line` → `MRP trace` → `PR/PO line` → `GR line (roll)` → `FQC` → `MI line` → `lay_rolls` → `bundle` → `line output` → `inspection` → `carton line` → `shipment line` → `AR invoice line`.
Setiap panah = kolom `source_document` + `source_document_line` (I-05).

---

## NEXT STEP

- Menjadi dasar `ERP_GARMENT_BUSINESS_RULES.md` (konsolidasi semua BR + default OBD) dan `ERP_GARMENT_DATABASE_BLUEPRINT.md` (FASE 3).
- Menunggu approval Anda untuk dikunci v1.0.
