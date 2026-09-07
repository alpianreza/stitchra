# Modul Purchasing

Alur purchasing mencakup PR opsional → RFQ → supplier quotation → comparison/award manual → PO DRAFT → approval existing → supplier invoice → 3-way match. Pembuatan PO langsung tetap tersedia.

## RFQ & sourcing

- Requirement RFQ disimpan per material/UOM/qty, dengan daftar supplier undangan dan referensi PR APPROVED opsional.
- Quotation wajib mencakup seluruh requirement; quantity/UOM tidak dapat ditimpa klien. Currency/rate, tanggal/validity, harga, lead time, dan terms disimpan sebagai snapshot.
- Comparison tidak menentukan auto-winner. Award wajib alasan, source valid, dan permission RFQ update serta PO create.
- Satu RFQ menghasilkan satu PO DRAFT dari satu quotation. Row lock dan unique source menjaga replay; payload award berbeda ditolak.
- PO header/line mempertahankan RFQ/quotation/PR lineage. Approval tetap melalui flow PO existing.
- Legacy quotation dengan snapshot tidak lengkap tidak ditebak. Split award, partial quotation, quote revision, dan cumulative PR allocation tidak ditambahkan.

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

Regression tests tersedia untuk approval rollback, full/partial receiving, match/mismatch, dan invoice sebelum receipt. [Iteration 27](../../../../../docs/ITERATION_27_PURCHASING_RECEIVING_COMPLETENESS.md) mencatat 28 targeted backend cases PASS, targeted UI PASS, serta TypeScript/Next build PASS. Full Pest masih 42 kegagalan yang juga muncul pada baseline; bukan global green atau production approval.
