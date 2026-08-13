# ERP GARMENT — BUSINESS RULES (konsolidasi)

> **Status:** ✅ LOCKED v1.0 — disetujui pemilik 13 Agustus 2026
> **Tanggal:** 13 Agustus 2026
> **Dasar:** FASE 0 v1.0 (LOCKED), FASE 1 Business Spec v1.0, MODULE_MAP v0.2, PROCESS_FLOW v0.2, DECISION_LOG DEC-2026-08-13-01 s/d 03
> **Tujuan:** Satu dokumen berisi SELURUH business rule yang mengikat implementasi. Setiap rule punya kode, status, dan sumber keputusan. Developer hanya boleh mengimplementasikan rule yang tercantum di sini; rule baru harus ditambahkan lewat OBD + approval.

---

## 0. LEGENDA STATUS

- ✅ **LOCKED** — diputuskan (via DEC), wajib diimplementasikan persis seperti tertulis.
- 🟡 **DEFAULT** — belum diputuskan eksplisit (OBD non-P0); implementasi mengikuti default ini sampai diganti keputusan resmi.
- ⚙️ **CONFIGURABLE** — nilai/ambang diatur via master data/settings, bukan hard-code.

---

## 1. CORE & DOKUMEN

| Kode | Status | Rule |
|---|---|---|
| BR-010 | ✅ LOCKED | Nomor dokumen `PREFIX-YYYY-NNNNNN` per company; counter terpisah per (company, prefix, tahun); concurrency-safe (DB-level); nomor dari dokumen batal **tidak di-reuse** (gap tercatat). Format & prefix configurable per doc-type. |
| BR-011 | ✅ LOCKED | Semua tabel transaksi & stok membawa `company_id` (+ `factory_id` bila relevan). Query wajib scope per company. |
| BR-012 | 🟡 DEFAULT (OBD-028) | Status baseline dokumen: `DRAFT → SUBMITTED → APPROVED → (IN_PROGRESS) → CLOSED`; cabang `REJECTED` (kembali ke draft) & `CANCELLED`. Dokumen yang sudah punya dokumen turunan **tidak bisa di-cancel** — gunakan dokumen reversal/return. |
| BR-015 | ✅ LOCKED | Approval dikelola engine terpusat: sequential & parallel, rejection, revision, delegation, history lengkap. Threshold nilai dari **approval matrix** (master data), tidak ada threshold di kode. |
| BR-016 | ✅ LOCKED | Audit log append-only untuk semua transaksi penting: user, waktu, aksi, dokumen+line, before→after (field-level), IP, device. Tidak ada fitur edit/hapus audit log untuk siapa pun. |
| BR-017 | ✅ LOCKED | Koreksi data historis (output, stok, dokumen closed) hanya via dokumen adjustment ber-approval — tidak ada edit langsung (OBD-015, OBD-026). |

## 2. SALES & ORDER

| Kode | Status | Rule |
|---|---|---|
| BR-020 | ✅ LOCKED | SKU = style × color × size. Order line memakai matrix line (satu baris per kombinasi), bukan JSON. |
| BR-021 | 🟡 DEFAULT (OBD-019) | Toleransi short/excess shipment per buyer/SO (umum ekspor ±3–5%), ⚙️ configurable; divalidasi saat packing & shipment. |
| BR-022 | 🟡 DEFAULT (OBD-021) | Order amendment diizinkan sampai **sebelum cutting dimulai**; setelah itu qty terkunci (perubahan hanya via addendum order baru). Amendment memicu MRP delta run. |
| BR-023 | ✅ LOCKED | SO tidak bisa di-confirm bila style belum punya BOM & Routing versi APPROVED. |
| BR-024 | 🟡 DEFAULT (OBD-020) | Packing instruction (solid / ratio pack / mixed) berasal dari SO per buyer; divalidasi saat carton dibentuk. |

## 3. PRODUCT DEVELOPMENT

| Kode | Status | Rule |
|---|---|---|
| BR-030 | ✅ LOCKED | BOM & Routing **versioned**; hanya versi APPROVED yang dipakai MRP/costing/produksi; perubahan pasca-approval = versi baru (tidak edit in-place). |
| BR-031 | ✅ LOCKED (BR-014) | Consumption disimpan ganda: `estimated` (formula sampling) dan `actual` (dari marker realisasi). Costing estimated memakai estimated + wastage% + shrinkage%; costing actual memakai realisasi marker + leftover return. |
| BR-032 | ✅ LOCKED | BOM line memuat: material, qty per pcs, UOM pakai, wastage%, shrinkage%, dan (untuk fabric) berlaku per colorway. |
| BR-033 | 🟡 DEFAULT (OBD-012) | SMV/SAM per operasi dimiliki oleh IE, versioned per style via routing; formula efisiensi ⚙️ configurable. |

## 4. MATERIAL & INVENTORY

| Kode | Status | Rule |
|---|---|---|
| BR-001 | ✅ LOCKED | Setiap stok punya `ownership` = COMPANY \| BUYER. Stok BUYER tidak ikut valuation & tidak di-nett MRP untuk order selain pemiliknya. |
| BR-002 | ✅ LOCKED | Kain dual UOM (qty beli + meter) dengan konversi per roll: `meter = kg × 1000 / (GSM × lebar_m)`; konversi tersimpan per roll/lot; toleransi selisih default ±0,5% ⚙️. |
| BR-003 | ✅ LOCKED | Fabric wajib **roll-level** + barcode; trim cukup **lot-level**. |
| BR-004 | ✅ LOCKED | Semua penerimaan masuk `QUALITY_HOLD`; available hanya setelah inspeksi PASS. Kategori trim boleh auto-pass ⚙️ per material category. |
| BR-005 | ✅ LOCKED | Valuation = **Moving Average** per item per company; ledger menyimpan qty & cost per transaksi (migrasi metode tetap dimungkinkan). |
| BR-006 | ✅ LOCKED | **Hard reservation** saat MO release; `available = on_hand − reserved − quality_hold`; stok tidak boleh negatif (constraint DB + validasi service). |
| BR-013 | ✅ LOCKED | Semua perubahan stok HANYA via **Inventory Transaction Service**: atomic (dokumen + lines + ledger + saldo dalam satu transaksi DB). Ledger append-only; koreksi via entri balik. |
| BR-040 | 🟡 DEFAULT (OBD-010) | Shortage antar order dialokasikan manual oleh planner; sistem memberi rekomendasi urutan by delivery date. |
| BR-041 | 🟡 DEFAULT (OBD-014) | Material issue **aktual** untuk fabric (per roll dari lay); trim murah boleh **backflush** dari output × BOM ⚙️ per material class. |
| BR-042 | ✅ LOCKED | Leftover fabric wajib kembali ke inventory (dengan panjang aktual per roll); wastage dicatat dan masuk actual cost. |
| BR-043 | 🟡 DEFAULT | Safety stock per material ⚙️ configurable; masuk perhitungan nett MRP. |

## 5. PURCHASING & RECEIVING

| Kode | Status | Rule |
|---|---|---|
| BR-050 | ✅ LOCKED | Supplier invoice wajib **3-way match** (PO–GR–invoice); toleransi harga/qty ⚙️ di approval matrix; mismatch → approval manual. |
| BR-051 | 🟡 DEFAULT | Partial receiving & partial shipment diizinkan; `received_qty` ter-agregasi di PO line; PO auto-`CLOSED` saat fully received atau di-close manual ber-approval. |
| BR-052 | ✅ LOCKED | GR fabric = satu line **per roll** (roll no, lot, shade, qty beli, meter aktual). |
| BR-053 | 🟡 DEFAULT (OBD-006) | Shade rule: satu lay tidak campur shade group ⚙️ per buyer; sistem memvalidasi saat alokasi roll ke lay. |

## 6. PRODUCTION (CUTTING, SEWING, FINISHING)

| Kode | Status | Rule |
|---|---|---|
| BR-060 | ✅ LOCKED | MO release memicu hard reservation; release **gagal** bila available kurang (tampilkan shortage list). |
| BR-061 | 🟡 DEFAULT (OBD-013) | Bundle size per style ⚙️ configurable (umum 10–20 pcs); setiap bundle punya barcode ticket unik (bundle id + CUT + style/color/size + qty). |
| BR-007 | ✅ LOCKED | Output sewing minimal **per line per hari**; struktur data siap **per operator per jam per bundle** (scan) untuk fase lanjut. |
| BR-062 | 🟡 DEFAULT (OBD-012) | Efisiensi = (SAM × output) / (manpower × menit kerja) × 100; target per style per line ⚙️ configurable, versioned. |
| BR-063 | 🟡 DEFAULT (OBD-015) | Koreksi output hari sebelumnya hanya via adjustment ber-approval supervisor + audit. |
| BR-064 | ✅ LOCKED | WIP berpindah antar proses via WIP transfer (ledger), bukan edit qty langsung. |

## 7. QUALITY

| Kode | Status | Rule |
|---|---|---|
| BR-008 | ✅ LOCKED | Final inspection: **AQL ISO 2859-1, General Level II**; default 2.5 major / 4.0 minor; critical = 0 (lot auto-reject); AQL per buyer ⚙️ configurable; engine menghitung code letter → sample size → Ac/Re. |
| BR-070 | 🟡 DEFAULT (OBD-017) | Rework maksimal ⚙️ N kali per pcs/bundle (default 2); setelah rework wajib re-inspection; disposition (repair/reject/second-grade/scrap) oleh QC Manager; scrap di atas threshold → Management. |
| BR-071 | 🟡 DEFAULT (OBD-018) | Barang reject tidak masuk packing tanpa disposition; second grade (bila dijual) menjadi stok FG terpisah dengan flag grade. |
| BR-072 | ✅ LOCKED | Semua defect tercatat per defect code (kategori + severity dari defect library) — tidak ada defect free-text. |

## 8. PACKING & SHIPMENT

| Kode | Status | Rule |
|---|---|---|
| BR-080 | ✅ LOCKED | Hanya pcs berstatus `QC_PASS` yang bisa masuk carton. |
| BR-081 | ✅ LOCKED | Isi carton divalidasi terhadap packing instruction (BR-024); carton punya nomor unik per shipment, dimensi, GW/NW. |
| BR-082 | 🟡 DEFAULT (OBD-019) | Shipment confirm divalidasi terhadap toleransi short/excess SO (BR-021). |
| BR-083 | ✅ LOCKED | Shipment confirm `(DEPARTED)` ⇒ ledger `SHIPMENT` (FG berkurang) + dasar COGS + draft AR invoice. |

## 9. SUBCONTRACTING

| Kode | Status | Rule |
|---|---|---|
| BR-090 | ✅ LOCKED | Material ke subcon = transfer ke lokasi virtual SUBCON (kepemilikan tetap company; tidak mengubah valuation); outstanding + aging dilacak. |
| BR-091 | ✅ LOCKED | Biaya jasa subcon masuk **actual cost MO** terkait dan AP. |
| BR-092 | 🟡 DEFAULT (OBD-002) | Modul subcon in-scope desain; implementasi di Phase 7 (flow) & Phase 8 (costing). |

## 10. COSTING & FINANCE

| Kode | Status | Rule |
|---|---|---|
| BR-009 | ✅ LOCKED | Estimated per style (revisi per SO); **actual per MO**; laporan per SO. Overhead = Σ(SAM × output) × OH rate per menit (rate per company per periode, ⚙️). Labor = output × rate (piece-rate/line; formula ⚙️). |
| BR-100 | ✅ LOCKED | Estimated cost sheet yang APPROVED menjadi **standard cost** untuk variance. |
| BR-101 | ✅ LOCKED (OBD-024 RESOLVED, DEC-2026-08-13-03) | Finance di ERP = **full General Ledger internal**: COA, journal (operasional otomatis + umum/manual), AR/AP, cash/bank, period closing, inventory valuation, COGS, laporan keuangan (trial balance, P&L, balance sheet dasar). Perusahaan sebelumnya Excel/manual → tidak ada integrasi akuntansi eksternal; modul ekspor journal bersifat **opsional**. |
| BR-102 | 🟡 DEFAULT (OBD-025) | Schema multi-currency sejak awal: dokumen menyimpan currency + exchange rate; selisih kurs ke journal. |
| BR-103 | 🟡 DEFAULT (OBD-026) | Period lock: transaksi di periode terkunci tidak bisa dibuat/diubah; koreksi via adjustment periode berjalan. |

## 11. SECURITY & AUDIT

| Kode | Status | Rule |
|---|---|---|
| BR-110 | ✅ LOCKED | Permission dicek **server-side** di setiap endpoint; granular `domain.entity.action`; `*.approve` terpisah dari `*.update`; data scope per company. |
| BR-111 | ✅ LOCKED | Auth: hashing modern (Argon2/bcrypt), session/JWT aman, lockout + rate limit login. |
| BR-112 | ✅ LOCKED | Proteksi CSRF, XSS (output encoding), SQL injection (prepared statements/ORM), validasi upload, security headers. |
| BR-113 | ✅ LOCKED | Password/kredensial & secret tidak pernah di-commit ke repo (scan via secret scanning). |

## 12. TRACEABILITY

| Kode | Status | Rule |
|---|---|---|
| BR-120 | ✅ LOCKED | Setiap dokumen turunan menyimpan `source_document` + `source_document_line` → traceability dua arah: Buyer PO → SO → MRP → PR/PO → GR(roll) → FQC → MI → lay/bundle → output → inspection → carton → shipment → AR. |
| BR-121 | ✅ LOCKED | MRP wajib menyimpan **trace perhitungan** (SO line → BOM line → gross → nett) agar user bisa menelusuri "kenapa butuh N meter?". |

---

## RINGKASAN

- **LOCKED:** 25 rule bertanda ✅ (termasuk BR-101 yang dikunci via DEC-2026-08-13-03).
- **DEFAULT (menunggu keputusan resmi):** 14 rule bertanda 🟡 — diimplementasikan sesuai default, mudah diubah karena ⚙️ configurable.
- Rule baru/perubahan → tambah baris di dokumen ini via OBD + approval (master prompt aturan 25).

## PENUTUP

Dokumen ini dikunci **v1.1** (kunci awal v1.0 + kunci BR-101 via DEC-03 dalam kesempatan yang sama). Menjadi acuan tunggal business rule untuk seluruh implementasi.
