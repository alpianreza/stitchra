# ERP GARMENT — ROLES & PERMISSIONS

> **Status:** ✅ LOCKED v1.0 — disetujui pemilik 13 Agustus 2026
> **Tanggal:** 13 Agustus 2026
> **Dasar:** FASE 1 Business Spec §4, BUSINESS_RULES v1.1 (BR-110), DEC-2026-08-13-01 s/d 03
> **Model:** RBAC granular. Permission format: `domain.entity.action`. Dicek **server-side**; scope data per `company_id`. Permission baru default **deny**.

---

## 1. ACTION STANDAR

| Action | Arti |
|---|---|
| `view` | Lihat list & detail |
| `create` | Buat draft |
| `update` | Ubah draft (bukan dokumen approved) |
| `delete` | Hapus draft (master: soft delete bila belum dipakai) |
| `submit` | Ajukan approval |
| `approve` | Setujui (terpisah dari update) |
| `reject` | Tolak dalam approval |
| `cancel` | Batalkan (hanya sebelum ada turunan — BR-012) |
| `execute` | Jalankan proses operasional (mis. mrp.run, opname.count) |
| `export` | Export/print |
| `manage` | Konfigurasi (settings, matrix) |

## 2. DAFTAR ROLE × PERMISSION

### Super Admin
`*` — semua permission semua company. Satu-satunya role yang bisa `core.settings.manage`, `core.numbering.manage`, `core.approval.manage` (definisi matrix).

### Admin
- `core.user.*`, `core.rbac.view`, `master.*.*` (semua master data)
- Tidak bisa: `core.rbac.manage` (ubah permission schema), `core.numbering.manage`

### Sales
- `master.customer.*`, `sales.order.*` (create/update/submit/cancel), `sales.inquiry.*`
- `pd.style.view`, `pd.costing.view`, `shipping.shipment.view`
- `reporting.sales.view`, `export`

### Merchandiser
- Semua permission Sales **plus**: `pd.sample.*`, `pd.costing.create/update/submit`, `sales.order.view-all` (lintas buyer), tracking end-to-end `reporting.traceability.view`

### Product Development
- `pd.*.*` (style, sample, sizespec, bom, routing, techpack — create/update/submit)
- `master.material.view`, `master.production.view` (operation/SMV)
- `sales.order.view`

### PPIC
- `planning.mrp.execute`, `planning.production.*`, `planning.cutplan.*`
- `production.mo.*` (create/update/submit/release)
- `inventory.stock.view`, `inventory.reservation.*`, `reporting.ppic.view`
- `purchasing.pr.create/update/submit`

### Purchasing
- `purchasing.*.*` (pr/rfq/po/invoice — create/update/submit/cancel)
- `master.supplier.*`
- `inventory.stock.view` (ketersediaan), `planning.mrp.view` (shortage)
- `reporting.purchasing.view`

### Warehouse
- `receiving.gr.*` (create/update/submit), `inventory.stock.view`, `inventory.transfer.*`, `inventory.adjustment.create/submit`, `inventory.opname.*`
- `production.issue.execute` (material issue), `cutting.leftover.execute` (leftover return)
- `reporting.inventory.view`

### Cutting
- `cutting.*.execute` (order, marker, lay, bundle), `cutting.order.view`
- `inventory.fabric-roll.view`, `production.wip.transfer`

### Production (Sewing & Finishing)
- `sewing.output.create`, `sewing.assignment.*`, `sewing.downtime.create/submit`
- `finishing.*.execute`
- `production.wip.transfer`, `cutting.bundle.view` (scan)

### QC
- `quality.inspection.*` (inline/endline/final), `quality.defect.*`, `quality.ncr.create/update/submit`, `quality.disposition.execute`
- `receiving.inspection.*` (inward fabric/trim)
- `reporting.quality.view`

### Packing
- `packing.*.*` (instruction view, packing list, carton)
- `quality.inspection.view` (status QC_PASS)

### Shipping
- `shipping.*.*` (plan, docs, container, tracking)
- `packing.packinglist.view`, `sales.order.view`
- `finance.ar-invoice.create/submit` (dari commercial invoice)

### Finance
- `finance.*.*` (ar, ap, payment, journal create/submit, valuation view)
- `purchasing.invoice.approve` (3-way match), `shipping.commercial-invoice.view`
- `costing.*.view`
- `reporting.finance.view`

### Accounting
- Semua permission Finance **plus**: `finance.period.lock`, `finance.journal.approve`, `finance.period-closing.execute`, `finance.report.view` (trial balance, P&L, balance sheet — full GL DEC-03), `master.finance.*` (COA, rates)

### Management
- `*.view` semua domain + `*.export`
- `*.approve` level akhir (sesuai approval matrix: PO di atas limit, scrap di atas threshold, dst.)
- `reporting.*.view`, `dashboard.*.view`
- Tidak bisa: create/update transaksi operasional (read + approve saja)

## 3. PERMISSION KHUSUS (di luar pola standar)

| Permission | Role | Catatan |
|---|---|---|
| `planning.mrp.execute` | PPIC | Menjalankan MRP run |
| `production.mo.release` | PPIC | Release MO → trigger hard reservation (BR-060) |
| `inventory.adjustment.approve` | Warehouse Manager → Finance | Dua level (BR audit) |
| `quality.disposition.execute` | QC Manager | Disposition NCR (BR-070) |
| `finance.period.lock` | Accounting | Lock periode (BR-103) |
| `finance.period-closing.execute` | Accounting | Tutup buku per periode (BR-101) |
| `core.audit.view` | Super Admin, Management | Audit log read-only |
| `costing.margin.view` | Management, Merchandiser (own buyer), Finance | Data margin sensitif — tidak semua role boleh lihat |
| `pd.costing.view` | Sales/Merchandiser | Harga/cost sensitif: scope terbatas |

## 4. ATURAN KEAMANAN

1. Semua permission dicek di **server-side** (middleware per endpoint); frontend hanya menyembunyikan tombol (BR-110).
2. Scope `company_id` wajib di setiap query (BR-011); user bisa punya akses ke >1 company via `user_companies`.
3. Approval action (`*.approve`) tidak pernah satu paket dengan `create/update` pada role yang sama untuk dokumen bernilai (segregation of duties), kecuali Super Admin.
4. Delegation approval tercatat (approver asli + delegasi) di history (BR-015).
5. Role → permission mapping disimpan di DB (`roles`, `permissions`, `role_permissions`, `user_roles`) — dapat diubah Admin tanpa deploy, **kecuali** schema permission (butuh Super Admin).

## 5. CONTOH DENY-BY-DEFAULT

- Operator produksi (memakai akun Production) **tidak bisa** melihat harga/cost/margin.
- Warehouse tidak bisa `purchasing.po.view` harga (hanya qty untuk receiving) → diimplementasikan sebagai field-level masking pada fase UI.
- QC tidak bisa mengubah output produksi; Production tidak bisa mengubah hasil inspeksi.

---

## PENUTUP

Dokumen ini dikunci **v1.0**. Matrix ini dijadikan tabel seed (`roles`, `permissions`, `role_permissions`) saat Phase 1 (Core Foundation) diimplementasikan.
