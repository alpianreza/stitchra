# DECISION LOG — ERP GARMENT (STITCHRA)

> Catatan resmi seluruh keputusan bisnis & arsitektur. Setiap keputusan di sini **mengikat desain**.
> Perubahan keputusan hanya boleh melalui OBD baru + persetujuan, lalu log ini diperbarui.

---

## DEC-2026-08-13-01 — Persetujuan OBD P0 (FASE 0)

- **Tanggal:** 13 Agustus 2026
- **Diputuskan oleh:** Pemilik proyek (Agen)
- **Keputusan:** Menyetujui seluruh rekomendasi P0 pada dokumen FASE 0, plus izin riset referensi tambahan bebas.
- **Dampak:** FASE 0 dinyatakan **LOCKED v1.0**. FASE 1 (Business Specification) dimulai berdasarkan keputusan berikut.

### Keputusan P0 yang dikunci

| OBD | Topik | Keputusan (sesuai rekomendasi) |
|---|---|---|
| OBD-001 | Model bisnis | Desain siap CMT + FOB (flag kepemilikan stok); implementasi awal FOB |
| OBD-004 | Satuan kain | Dual UOM (beli: kg/meter/yard; pakai: meter); konversi GSM×lebar **per roll/lot** + toleransi selisih |
| OBD-005 | Level tracking kain | **Roll-level** untuk fabric (barcode per roll); lot-level untuk trim |
| OBD-007 | Kapan stok available | Setelah lulus Inward QC; stok masuk berstatus `QUALITY_HOLD` |
| OBD-008 | Inventory valuation | **Moving Average**; ledger menyimpan cost per transaksi agar migrasi metode memungkinkan |
| OBD-009 | Reservasi stok | **Hard reservation** saat MO release; laporan shortage sejak SO confirm |
| OBD-011 | Granularitas sewing | Desain mendukung per operator/jam (scan bundle); implementasi bertahap mulai per line/hari |
| OBD-016 | AQL | AQL engine configurable per buyer (default 2.5 major / 4.0 minor, ISO 2859-1 General Level II) |
| OBD-022 | Level costing | Estimated per style (revisi per SO), **actual per MO**, laporan per SO |
| OBD-023 | Basis overhead | **Per menit SAM terpakai**, configurable |
| OBD-024 | Scope Finance | Hook integrasi disiapkan; implementasi awal AR/AP + costing di ERP (full GL kemudian — lihat catatan di bawah) |
| OBD-027 | Document numbering | Per company + prefix + tahun, counter terpisah, concurrency-safe |
| OBD-029 | Multi-company | Schema siap (company_id/factory_id di semua tabel transaksi & stok); operasional 1 company dulu |

### Catatan
- OBD-024: keputusan akhir "full GL vs integrasi software akuntansi existing" **masih menunggu info software akuntansi yang dipakai saat ini** (pertanyaan FASE 0 no. 27). Sementara desain memakai pendekatan: AR/AP/journal operasional di ERP + interface ekspor journal.
- OBD non-P0 (002, 003, 006, 010, 012–015, 017–021, 025, 026, 028, 030–032) tetap terbuka dan dijadwalkan keputusannya per fase di `ERP_GARMENT_IMPLEMENTATION_ROADMAP.md`. Rekomendasi pada FASE 0 menjadi default sementara sampai diganti keputusan resmi.

### Referensi riset tambahan yang diadopsi
- AQL: ISO 2859-1 single sampling, General Inspection Level II; code letter dari lot size; switching rules (normal → tightened → reduced) dicatat sebagai fitur lanjutan.
- Costing: struktur cost sheet FOB = Fabric + Trim + CM; CM dihitung dari **SMV × cost-per-minute**; landed cost = FOB + freight + duty + handling.
- Fabric consumption tahap sampling memakai formula estimasi (pattern-based), tahap bulk memakai marker actual — keduanya disimpan terpisah (estimated vs actual consumption).

---

## DEC-2026-08-13-02 — Tech Stack (TD-01 RESOLVED)

- **Tanggal:** 13 Agustus 2026
- **Diputuskan oleh:** Pemilik proyek (Agen)
- **Keputusan:** TD-01 diputuskan memakai stack pilihan pemilik (varian OPSI B: backend Laravel, frontend React/Next.js — bukan NestJS):

| Layer | Keputusan | Catatan implementasi |
|---|---|---|
| Backend | **Laravel 13 + PHP 8.5** | Business logic, modular monolith (modul per Module Map) |
| Frontend | **React + Next.js 16** | UI ERP: tabel kompleks, dashboard, workflow interaktif |
| Database | **PostgreSQL 18** | Transaction-heavy; CHECK constraint stok ≥ 0, row locking untuk reservasi/numbering |
| Cache | **Redis 8** | Cache, queue, session, locking |
| Queue | **Laravel Horizon + Redis** | Job berat: MRP run, actual costing, report, import/export |
| Realtime | **Laravel Reverb (WebSocket)** | Live dashboard produksi (output line, line loading) |
| API | **Laravel API / REST** | Integrasi mobile, barcode scanner, mesin, sistem eksternal |
| Auth | **Laravel Sanctum** | SPA (Next.js) + API token untuk perangkat shop floor |
| File storage | **S3-compatible** | Tech pack, foto QC, invoice, dokumen ekspor |
| Search | **PostgreSQL FTS → OpenSearch bila perlu** | Tidak menambah search engine di awal |
| PDF | **Browsershot/Chromium** | Packing list, commercial invoice, report |
| Excel | **Laravel Excel** | Import/export (master data, report) |
| Testing | **Pest + PHPUnit + Playwright** | Unit → feature → E2E (sesuai FASE 21 master prompt) |
| Container | **Docker** | Dev/staging/production konsisten |
| Reverse Proxy | **Nginx** | Production |
| CI/CD | **GitHub Actions** | Automated test/deploy |
| Monitoring | **Sentry + Laravel Telescope/Pulse** | Error & application monitoring |

- **Catatan pelaksanaan (mengikat Phase 1):**
  1. Pin versi pasti di `composer.json`/`package.json`; verifikasi kompatibilitas paket (Horizon, Laravel Excel, Browsershot) terhadap Laravel 13 di awal Phase 1.
  2. Telescope hanya aktif di local/staging; production memakai Pulse + Sentry.
  3. Scope multi-company (BR-011) diimplementasikan via **global scope + middleware `company_id`** di Laravel.
  4. Inventory Transaction Service (BR-013) diimplementasikan sebagai domain service Laravel dengan DB transaction (dokumen + lines + ledger + saldo atomic).
- **Dampak:** `ERP_GARMENT_IMPLEMENTATION_ROADMAP.md` v0.2 — TD-01 resolved, struktur repo disesuaikan (`apps/api` = Laravel, `apps/web` = Next.js).
- **Masih terbuka:** TD-02 (deployment cloud/on-prem/hybrid), TD-03 (perangkat shop floor, sebelum Phase 6).

---

## Format entri berikutnya

```
## DEC-YYYY-MM-DD-NN — Judul
- Tanggal:
- Diputuskan oleh:
- Keputusan:
- OBD terkait:
- Dampak (dokumen/modul yang berubah):
```
