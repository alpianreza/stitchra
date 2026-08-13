# ERP GARMENT — IMPLEMENTATION ROADMAP

> **Status:** DRAFT v0.2 — TD-01 resolved (DEC-2026-08-13-02), fix rujukan TD-02, subcon dipindah ke Phase 7; menunggu approval kunci v1.0
> **Tanggal:** 13 Agustus 2026
> **Dasar:** seluruh blueprint (FASE 0 → Database Blueprint) + BLUEPRINT_REVIEW, master prompt FASE 22
> **Aturan:** satu fase selesai + teruji + direview sebelum fase berikutnya. Tidak ada coding sebelum dokumen ini disetujui.

---

## 1. KEPUTUSAN TEKNIS

### TD-01 — Tech stack ✅ RESOLVED (DEC-2026-08-13-02)

Stack pilihan pemilik (mengikat):

| Layer | Keputusan |
|---|---|
| Backend | Laravel 13 + PHP 8.5 (modular monolith, modul = Module Map) |
| Frontend | React + Next.js 16 |
| Database | PostgreSQL 18 (CHECK constraint stok ≥ 0, row locking) |
| Cache / Queue | Redis 8 + Laravel Horizon (job berat: MRP, costing, report, import/export) |
| Realtime | Laravel Reverb (WebSocket) — live dashboard produksi |
| API / Auth | Laravel REST API + Sanctum (SPA + token perangkat shop floor) |
| File | S3-compatible storage (tech pack, foto QC, invoice) |
| Search | PostgreSQL FTS → OpenSearch bila perlu |
| PDF / Excel | Browsershot (Chromium) / Laravel Excel |
| Testing | Pest + PHPUnit + Playwright (E2E) |
| Infra | Docker + Nginx + GitHub Actions CI/CD |
| Monitoring | Sentry + Laravel Telescope (non-prod) / Pulse (prod) |

Catatan pelaksanaan (dari DEC-2026-08-13-02): pin versi di composer/package.json; verifikasi kompatibilitas paket terhadap Laravel 13 di awal Phase 1; Telescope hanya non-prod; scope multi-company via global scope + middleware `company_id`; ITS (BR-013) sebagai domain service dengan DB transaction.

### TD-02 — Deployment `DECISION REQUIRED` (terkait pertanyaan discovery FASE 0 no. 33)
- OPSI A: Cloud (Docker di VPS/managed) — maintenance ringan, akses multi-site mudah.
- OPSI B: On-premise di pabrik — tahan internet putus, beban maintenance sendiri.
- OPSI C: Hybrid (app on-prem + backup/sync cloud).
- Rekomendasi: mulai **OPSI A** dengan requirement offline-tolerance untuk modul shop floor di fase 6 (queue lokal saat scan).

### TD-03 — Perangkat shop floor (OBD-031) `DECISION REQUIRED` sebelum Phase 6
- Browser-based (tablet/HP + kamera/scan gun USB) direkomendasikan: tanpa instalasi, murah.

---

## 2. ROADMAP FASE (mengikuti master prompt FASE 22)

> Setiap fase: Analyze → Identify dependencies/risks → Propose → **Approval bila arsitektural/bisnis** → Implement → Test (unit+feature) → Review → Document.
> Estimasi dalam *minggu kerja tim kecil (1–2 dev)* — indikatif, direvisi setelah Phase 1.

### PHASE 1 — Core Foundation (±3–4 minggu)
**Scope:** organization, user+auth (Sanctum), RBAC, approval engine, numbering, audit log, settings, notification dasar, layout app + i18n skeleton.
- Deliverable: login, manajemen user/role/permission, approval engine terdaftar sebagai shared service, numbering service concurrency-safe (test: 100 request paralel → 100 nomor unik), audit interceptor otomatis (Laravel observer/middleware).
- Keputusan dibutuhkan: TD-02.
- Risiko: fondasi salah → semua fase rework. Mitigasi: review arsitektur di akhir fase.

### PHASE 2 — Master Data (±3–4 minggu)
**Scope:** customer(+AQL cfg, toleransi), supplier(+tipe subcon), employee, style+colorway+shade, size & size range, UOM+conversion, material (fabric GSM/width/shrinkage, trim, packaging), warehouse+location (termasuk SUBCON virtual), machine, line, operation+SMV version, defect library, COA, currency+rate, OH rate, line cost rate, approval matrix, import CSV master (Laravel Excel).
- Test: validasi unik per company, soft delete terkunci bila dipakai, konversi UOM.
- Risiko: kualitas master menentukan MRP/costing (risiko #3 FASE 0) → sediakan template impor + validasi.

### PHASE 3 — Sales, BOM, Routing, PD, Costing Estimasi (±4–5 minggu)
**Scope:** SO + matrix line + amendment + delivery schedule; style spec/measurement/tech pack (upload S3); sample cycle; BOM versioned (est consumption, wastage, shrinkage); routing versioned (SAM); pre-production cost sheet (CM = SAM × cost/min; OH = SAM × OH rate) → standard cost.
- Test: BR-023 (SO confirm butuh BOM+Routing approved), versioning tidak edit in-place, hitung FOB.
- Keputusan: OBD-012 (pemilik SAM) paling lambat akhir fase.

### PHASE 4 — Inventory, Purchasing, Receiving (±5–6 minggu) — **fase paling kritis**
**Scope:** stock ledger + balances + ITS (satu-satunya pintu tulis, atomic via DB transaction), reservation engine (hard), transfer/adjustment/opname; PR→RFQ→PO→3-way match; GR roll-level + fabric_rolls (dual UOM per roll, BR-002/052) + inward inspection (4-point/shrinkage/GSM/shade) + quality hold release + supplier return.
- Test wajib: race condition issue paralel (stok tidak pernah negatif — `SELECT ... FOR UPDATE` + CHECK constraint), rollback atomic (dokumen gagal ⇒ ledger tidak berubah), konversi kg↔meter per roll ± toleransi, moving average benar setelah return/adjustment.
- Keputusan: OBD-014 (backflush trim), OBD-006 (shade rule) paling lambat akhir fase.

### PHASE 5 — MRP, Production Planning, MO (±3–4 minggu)
**Scope:** MRP run (via Horizon job) + requirement + trace ("kenapa butuh N?"), safety stock, open PO netting, ownership netting (BR-001); production plan + line loading; MO + release → hard reservation; amendment → MRP delta; material issue (aktual fabric per roll).
- Test: contoh master prompt (10.000 pcs × 1,8 m; gross 18.000; available 5.000; reserved 1.000; open PO 3.000 → nett 11.000) harus tereproduksi dan traceable.

### PHASE 6 — Cutting, Sewing, Finishing, WIP (±5–6 minggu)
**Scope:** cut plan → cutting order → marker/efficiency → lay + roll allocation (shade validation) → cut output → bundling + barcode ticket → WIP transfer; line assignment; line output harian (+struktur siap operator scan); downtime; finishing + repair; leftover return → inventory; wastage → costing hook; live progress via Reverb.
- Test: leftover per roll benar (100 − 92 = 8 kembali ke stok), barcode unik, efisiensi = (SAM×output)/(manpower×menit)×100.
- Keputusan: TD-03 (perangkat), OBD-013 (bundle size), OBD-015 (koreksi output) sebelum mulai.
- UX khusus: halaman operator scan-first, keyboard/barcode friendly, mobile responsive (FASE 18).

### PHASE 7 — QC, Rework, Packing, Shipment, Subcontracting (±4–5 minggu)
**Scope:** inline/endline inspection, defect library enforcement; **AQL engine ISO 2859-1 G-II** (code letter → sample size → Ac/Re; default 2.5/4.0; per buyer config); NCR + disposition + rework counter + re-inspection; packing instruction validation; carton + packing list; shipment plan + toleransi short/excess; container; commercial invoice; dokumen ekspor; FG receipt & shipment stock-out; **subcontracting: job work order + material out/in lokasi SUBCON + aging vendor (OBD-002/BR-092)**.
- Test: lot 1.200 @AQL2.5 → sample 80, Ac 5 / Re 6 (sesuai tabel); hanya QC_PASS masuk carton; toleransi buyer ditegakkan; stok di lokasi SUBCON tidak mengubah valuation (BR-090).
- Keputusan: OBD-002, OBD-017, OBD-018, OBD-019, OBD-020, OBD-021.

### PHASE 8 — Costing & Finance (±4–6 minggu)
**Scope:** biaya jasa subcon → actual cost MO (BR-091); actual cost per MO (material dari ledger, labor, OH per SAM-minute, subcon, wastage) + variance vs standard + margin per SO/style/buyer; AR (dari CI) + aging + payment + selisih kurs; AP + payment; journal operasional + period lock; **ekspor journal** ke software akuntansi (OBD-024).
- Keputusan: OBD-024 (jawaban software akuntansi), OBD-025, OBD-026 sebelum mulai fase.

### PHASE 9 — Dashboard, Reporting, Hardening (±3–4 minggu)
**Scope:** dashboard Management/PPIC/Warehouse/Production/QC (data nyata dari ledger/summary, live via Reverb); report per domain (FASE 1 §13) + export Excel/PDF; traceability viewer (roll→carton; MRP trace); performance tuning (index, summary tables, partition ledger bila perlu); security review; regression test menyeluruh (Playwright); UAT + pilot 1–3 style + 1 line.

**Total indikatif: ±32–42 minggu** untuk tim 1–2 developer (belum termasuk paralelisasi). Setiap fase bisa diparalelkan sebagian setelah Phase 4 stabil.

---

## 3. KEBUTUHAN KEPUTUSAN PER FASE (ringkasan)

| Fase | Keputusan yang harus sudah dijawab |
|---|---|
| 1 | TD-02 (deployment) |
| 2 | — (data profil bisnis membantu: jumlah warehouse/line/operator) |
| 3 | OBD-012 (pemilik SAM) |
| 4 | OBD-006 (shade), OBD-014 (backflush trim) |
| 5 | OBD-010 (alokasi shortage) |
| 6 | TD-03 (perangkat), OBD-013, OBD-015 |
| 7 | OBD-002, OBD-017, OBD-018, OBD-019, OBD-020, OBD-021 |
| 8 | **OBD-024** (software akuntansi existing), OBD-025, OBD-026 |
| 9 | OBD-030 (multi-language operasional) |

## 4. DEFINITION OF DONE PER FASE

1. Semua endpoint lolos test permission (BR-110) & validasi.
2. Test minimum master prompt FASE 21: CRUD, submit, approve, reject, cancel, stock movement, permission, validation, **concurrency**.
3. Audit log terverifikasi untuk tiap transaksi fase tersebut.
4. Dokumen teknis modul (README per modul) + update blueprint bila ada deviasi (via OBD/DEC baru).
5. Demo ke pemilik + approval sebelum fase berikutnya.

## 5. STRUKTUR REPO (mengikuti DEC-2026-08-13-02)

```
stitchra/
├─ docs/                    # seluruh blueprint (folder ini)
├─ apps/
│  ├─ api/                  # Laravel 13 — modular monolith (modul = Module Map)
│  └─ web/                  # Next.js 16 (React) — SPA via Sanctum
├─ packages/
│  └─ config/               # shared config (eslint, tsconfig, docker-compose)
└─ infra/                   # Dockerfiles, nginx, GitHub Actions workflows
```

---

## NEXT STEP

1. Anda review seluruh dokumen blueprint (9 dokumen di `docs/`).
2. Jawab **TD-02** (deployment) — satu-satunya keputusan tersisa sebelum Phase 1; bila bisa sekalian **OBD-024** (software akuntansi) & profil bisnis FASE 0 no. 1–4.
3. Setelah roadmap disetujui → kunci semua dokumen **v1.0** → mulai **Phase 1 coding** (Core Foundation).
