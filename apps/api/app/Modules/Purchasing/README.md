# Modul Purchasing

Alur purchasing mencakup PR → PO → supplier invoice → 3-way match.

## Invariants

- PR/PO line wajib memiliki qty positif dan reference tenant-scoped.
- Total PO dihitung server-side dari qty × unit price.
- Supplier, currency, material, UOM, dan PR line harus berasal dari company aktif.
- Expected date tidak boleh sebelum order date; exchange rate wajib positif bila currency digunakan.
- Submit PR/PO menggunakan row lock dan transaksi yang sama dengan approval request.
- Approval promotion hanya menerima status `SUBMITTED` dan idempotent terhadap status `APPROVED`.

## Three-way match

- Supplier dan company invoice harus cocok dengan PO.
- Setiap invoice line harus menunjuk PO line pada PO invoice dan tidak boleh duplikat.
- Invoice sebelum ada receipt selalu `MISMATCH`.
- Harga invoice dibandingkan dengan PO; qty invoice dibandingkan dengan cumulative received qty.
- Tolerance endpoint berasal dari konfigurasi server (`PURCHASING_PRICE_TOLERANCE_PCT` dan `PURCHASING_QTY_TOLERANCE_PCT`), bukan input bebas klien.

## Verification status

Regression tests tersedia untuk approval rollback, full/partial receiving, match/mismatch, dan invoice sebelum receipt. Runtime result belum dinyatakan hijau sampai lockfile tersedia dan CI dijalankan.
