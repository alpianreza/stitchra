# Modul Planning (MRP)

MRP run dari SO `CONFIRMED`: BOM explode → gross → netting → shortage → saran PR.

## Invariants

- Seluruh SO pilihan wajib berasal dari company aktif dan berstatus `CONFIRMED`; selection parsial ditolak atomic.
- Run number diserialisasi dengan company row lock dan dilindungi unique constraint.
- Gross memakai BOM `APPROVED` dan `grossPerPcs()`; UOM material harus konsisten.
- Need date agregat memakai tanggal kebutuhan paling awal.
- `available = on_hand - reserved - quality_hold`; on-order hanya sisa PO `APPROVED/PARTIAL_RECEIVED`.
- Query on-order tidak merujuk kolom soft-delete yang tidak ada pada `purchase_orders`.
- Requirement unik per run×material.
- MRP tetap read-only: tidak membuat PR/PO otomatis.
- Konversi requirement→PR menggunakan lock, wajib berasal dari run yang sama, net > 0, belum dikonversi, dan berada dalam transaksi yang sama dengan pembuatan PR.

## Verification status

Regression tests tersedia untuk exact netting, BOM wastage, no-auto-PR, BR-120 trace, selection atomicity, dan duplicate conversion. Runtime result belum dinyatakan hijau sampai lockfile tersedia dan CI dijalankan.
