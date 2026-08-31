# Modul Receiving & Inward QC

Goods Receipt, fabric roll tracking, inward inspection, dan supplier return.

## Invariants

- GR hanya dapat dibuat dari PO `APPROVED` atau `PARTIAL_RECEIVED` pada company aktif.
- PO header dan PO line dikunci selama receipt; over-receipt ditolak secara atomic.
- Material, UOM, dan unit cost GR diturunkan dari PO line, bukan payload klien.
- Fabric wajib per roll dan total `qty_buy` roll harus sama dengan `qty_received`.
- Fabric diposting ke ITS sebagai balance per roll; trim/packaging tetap lot-level.
- Konversi meter memakai GSM/width aktual bila tersedia dan menyimpan conversion rate per roll.
- Seluruh receipt masuk quality hold.
- Inspection line harus berasal dari GR yang sama; roll harus berasal dari GR line yang sama.
- Finalize QC mengambil material, warehouse, UOM, lot, roll, dan qty dari data server, menggunakan row lock, dan idempotent melalui `finalized_at`.
- Mixed PASS/FAIL menghasilkan status GR line `PARTIAL`; supplier return mengeluarkan rejected roll dari quality hold yang tepat.

## Verification status

Regression tests tersedia untuk roll conversion, per-roll balances, PASS/FAIL release, idempotent finalize, supplier return, partial receipt, over-receipt rollback, dan PO-price derivation. Runtime result belum dinyatakan hijau sampai lockfile tersedia dan CI dijalankan.
