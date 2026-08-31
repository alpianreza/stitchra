# Modul Inventory

`InventoryTransactionService` (ITS) adalah satu-satunya pintu perubahan stok.

## Invariants

- Ledger bersifat append-only; koreksi dibuat sebagai transaksi balik, bukan update/delete.
- Movement hanya menerima type yang dikenal dan idempotent berdasarkan company + type + source document.
- Header, ledger, dan materialized balance ditulis dalam satu transaksi.
- First-balance creation diserialisasi melalui deterministic balance lock key, termasuk untuk dimensi nullable.
- ITS memvalidasi user, material/style variant, warehouse, location, UOM, roll, ownership, dan company secara langsung.
- `available = on_hand - reserved - quality_hold`; issue tidak dapat membuat stok negatif.
- Material issue dapat mengonsumsi reserved stock, sedangkan purchase return hanya mengonsumsi quality hold.
- Moving average dihitung pada inflow berbiaya dan dipertahankan saat transfer antar gudang.

## Operations

- Transfer dikunci pada state transition `DRAFT → IN_TRANSIT → RECEIVED` dan posting dua sisi idempotent.
- Adjustment dan opname tidak mengubah stok sebelum approval.
- Submit adjustment/opname dan pembuatan approval request berada dalam transaksi yang sama.
- Approval application dikunci, idempotent, dan mengubah dokumen menjadi `APPROVED`.
- Opname wajib menghitung seluruh snapshot line tepat satu kali.

## Verification status

Regression tests tersedia untuk atomic rollback, idempotency, reserved issue, quality hold, moving average, transfer valuation, approval rollback, opname completeness, append-only ledger, dan tenant isolation. Runtime result belum dinyatakan hijau sampai lockfile tersedia dan CI dijalankan dari clean checkout.
