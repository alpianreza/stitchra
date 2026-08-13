# BLUEPRINT REVIEW — REVIEW KONSISTENSI ANTAR DOKUMEN

> **Status:** FINAL — review selesai, perbaikan diterapkan
> **Tanggal:** 13 Agustus 2026
> **Scope:** Seluruh dokumen blueprint di `docs/` (STEP 1–7 master prompt) + DECISION_LOG
> **Tujuan:** Memastikan tidak ada kontradiksi antar dokumen sebelum blueprint dikunci dan coding dimulai (master prompt: "Jangan coding sebelum blueprint cukup matang").

---

## 1. DOKUMEN YANG DIREVIEW

| # | Dokumen | Versi saat review |
|---|---|---|
| 1 | `FASE_0_BUSINESS_DISCOVERY.md` | v1.0 LOCKED |
| 2 | `ERP_GARMENT_BUSINESS_SPECIFICATION.md` | v0.1 |
| 3 | `ERP_GARMENT_MODULE_MAP.md` | v0.1 → **v0.2 (diperbaiki)** |
| 4 | `ERP_GARMENT_PROCESS_FLOW.md` | v0.1 → **v0.2 (diperbaiki)** |
| 5 | `ERP_GARMENT_BUSINESS_RULES.md` | v0.1 |
| 6 | `ERP_GARMENT_DATABASE_BLUEPRINT.md` | v0.1 |
| 7 | `ERP_GARMENT_ROLES_PERMISSIONS.md` | v0.1 |
| 8 | `ERP_GARMENT_IMPLEMENTATION_ROADMAP.md` | v0.1 → **v0.2 (diperbaiki)** |
| 9 | `DECISION_LOG.md` | DEC-2026-08-13-01 → **+ DEC-2026-08-13-02** |

---

## 2. TEMUAN & PERBAIKAN

### Temuan yang diperbaiki (4)

| # | Lokasi | Temuan | Perbaikan |
|---|---|---|---|
| F-1 | MODULE_MAP §2.7 (`receiving.gr`) | Sitasi "(BR-003/005)" — BR-005 adalah moving average, tidak relevan dengan roll-level GR | Diubah → "(BR-003/052)" (roll-level & GR per roll) |
| F-2 | PROCESS_FLOW PF-03 | Sitasi "(BR-003/005)" — sama seperti F-1 | Diubah → "(BR-003/052)" |
| F-3 | PROCESS_FLOW PF-05 | Leftover/wastage hanya merujuk BR-014 (estimated vs actual consumption) | Diubah → "(BR-014/BR-042)" — BR-042 adalah rule leftover return & wastage |
| F-4 | ROADMAP | (a) TD-02 salah rujuk "OBD-033" (OBD hanya sampai 032); (b) Subcontracting ditempatkan di Phase 8, bertentangan dengan default OBD-002/BR-092 ("implementasi fase 6–7") | (a) Diubah → "(terkait pertanyaan discovery FASE 0 no. 33)"; (b) Modul subcon (job work order, material out/in, aging) dipindah ke **Phase 7**; Phase 8 menjadi "Costing & Finance" (biaya jasa subcon → actual cost tetap di Phase 8); tabel keputusan Phase 7 ditambah OBD-002 |

### Divergensi yang dievaluasi & dinyatakan BUKAN inkonsistensi

| # | Item | Hasil evaluasi |
|---|---|---|
| E-1 | FASE 0 §10 menyebut "9 P0", DECISION_LOG memuat 13 baris OBD P0 | Versi LOCKED FASE 0 sudah tidak menyebut angka 9; 13 item P0 di DECISION_LOG adalah superset yang benar (OBD-001, 004, 005, 007, 008, 009, 011, 016, 022, 023, 024, 027, 029). **Konsisten.** |
| E-2 | Rumus available stock: master prompt `on_hand − reserved` vs BR-006 `on_hand − reserved − quality_hold` | Bukan kontradiksi — BR-006 adalah perluasan hasil OBD-007 (quality hold). `on_hand` di `stock_balances` adalah total fisik termasuk yang hold. **Konsisten, definisi dikunci di Database Blueprint §3.8.** |
| E-3 | Master prompt FASE 22 tidak menyebut modul subcon; roadmap menempatkannya eksplisit | Subcon adalah hasil discovery (OBD-002) yang disetujui — penambahan sah lewat mekanisme OBD. **Konsisten setelah F-4.** |
| E-4 | Tech stack: roadmap v0.1 merekomendasikan OPSI A (NestJS); pemilik memilih Laravel + Next.js | Keputusan pemilik mengesampingkan rekomendasi — sah secara governance. Dicatat di DEC-2026-08-13-02. Blueprint bisnis stack-agnostic, **tidak ada dokumen lain yang perlu berubah** selain roadmap (sudah di v0.2). |

---

## 3. MATRIKS KONSISTENSI

### 3.1 OBD → Business Rule (32/32 terpetakan)

| OBD | Status | Menjadi rule | OBD | Status | Menjadi rule |
|---|---|---|---|---|---|
| 001 | ✅ LOCKED | BR-001 | 017 | 🟡 DEFAULT | BR-070 |
| 002 | 🟡 DEFAULT | BR-090/091/092 | 018 | 🟡 DEFAULT | BR-071 |
| 003 | 🟡 DEFAULT | Out-of-scope awal (FASE 1 §2) | 019 | 🟡 DEFAULT | BR-021/082 |
| 004 | ✅ LOCKED | BR-002 | 020 | 🟡 DEFAULT | BR-024 |
| 005 | ✅ LOCKED | BR-003 | 021 | 🟡 DEFAULT | BR-022 |
| 006 | 🟡 DEFAULT | BR-053 | 022 | ✅ LOCKED | BR-009/100 |
| 007 | ✅ LOCKED | BR-004 | 023 | ✅ LOCKED | BR-009 |
| 008 | ✅ LOCKED | BR-005 | 024 | 🟡 SEMENTARA | BR-101 (menunggu info software akuntansi) |
| 009 | ✅ LOCKED | BR-006/060 | 025 | 🟡 DEFAULT | BR-102 |
| 010 | 🟡 DEFAULT | BR-040 | 026 | 🟡 DEFAULT | BR-103 |
| 011 | ✅ LOCKED | BR-007 | 027 | ✅ LOCKED | BR-010 |
| 012 | 🟡 DEFAULT | BR-033/062 | 028 | 🟡 DEFAULT | BR-012 |
| 013 | 🟡 DEFAULT | BR-061 | 029 | ✅ LOCKED | BR-011 |
| 014 | 🟡 DEFAULT | BR-041 | 030 | 🟡 DEFAULT | Database Blueprint §7 (kolom label i18n) |
| 015 | 🟡 DEFAULT | BR-017/063 | 031 | 🟡 DEFAULT | TD-03 (roadmap, sebelum Phase 6) |
| 016 | ✅ LOCKED | BR-008 | 032 | 🟡 DEFAULT | `integration_jobs` + hook ekspor |

### 3.2 Modul → Tabel (20/20 tercakup di Database Blueprint)

Core ✓ (§3.1) · Master Data ✓ (§3.2) · Sales ✓ (§3.3) · PD ✓ (§3.4) · Planning ✓ (§3.5) · Purchasing ✓ (§3.6) · Receiving ✓ (§3.7) · Inventory ✓ (§3.8) · Production ✓ (§3.9) · Cutting ✓ (§3.10) · Sewing & Finishing ✓ (§3.11) · Quality ✓ (§3.12) · Packing ✓ (§3.13) · Shipping ✓ (§3.13) · Subcon ✓ (§3.14) · Costing ✓ (§3.15) · Finance ✓ (§3.16) · Reporting ✓ (view/summary — sengaja tanpa tabel transaksi) · Integration ✓ (`integration_jobs`, `export_batches`) · Audit ✓ (`audit_logs`).

### 3.3 Status enum
FASE 1 §10 ↔ Database Blueprint §4: **selaras** (baseline dokumen, MO, GR line/roll, bundle, inspection, disposition, reservation, shipment, approval, supplier invoice match). Tidak ada status yang dipakai di process flow tapi tidak terdaftar di blueprint.

### 3.4 Prefix dokumen
FASE 0 §4 (30 dokumen) ↔ Process Flow ↔ Database Blueprint (`doc_numbering_configs`): **selaras** — SO, SOA, SMPL, COST, PR, RFQ, PO, GR, FQC, MO, CUT, MI, WIP, OUT, QC, NCR, RW, JW, PKL, SHP, INV, AR/AP, PV/JV, ADJ/OPN. Semua lewat numbering service (BR-010).

### 3.5 Roles
FASE 1 §4 (16 role) ↔ ROLES_PERMISSIONS: **selaras**; permission format `domain.entity.action` konsisten dengan BR-110; segregation of duties (create vs approve) terdokumentasi.

### 3.6 Roadmap ↔ Modul
Semua modul di Module Map memiliki fase implementasi di Roadmap (Phase 1–9). Setelah F-4, subcon di Phase 7 (flow) & Phase 8 (costing) — selaras dengan BR-092.

---

## 4. VERDICT

✅ **Blueprint konsisten internal setelah 4 perbaikan (F-1 s/d F-4).** Seluruh rule bernomor (BR) tertelusur ke OBD/master prompt; tidak ada tabel tanpa dasar rule; tidak ada rule tanpa tabel; tidak ada kontradiksi tersisa yang diketahui.

**Blueprint siap dikunci v1.0** — menunggu approval akhir pemilik atas dokumen v0.2.

## 5. YANG MASIH HARUS DIPUTUSKAN SEBELUM/SAAT CODING

| Item | Dibutuhkan sebelum |
|---|---|
| TD-02 deployment (cloud/on-prem/hybrid) | Phase 1 |
| OBD-024 final (software akuntansi existing) | Phase 8 (desain hook sudah aman) |
| TD-03 perangkat shop floor (OBD-031) | Phase 6 |
| Profil bisnis (FASE 0 §9 no. 1–4, 11, 15–22, 24–26, 31–36) | Mempertajam Phase 2+ (tidak menghalangi Phase 1) |

## 6. LANGKAH SETELAH INI

1. Pemilik approve blueprint → semua dokumen dikunci **v1.0**.
2. Phase 1 (Core Foundation) dimulai: analyze → propose → approval → implement → test → review → document (aturan FASE 23 master prompt).
