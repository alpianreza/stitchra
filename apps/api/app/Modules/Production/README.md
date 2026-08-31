# Modul Production

MO menyimpan snapshot BOM/routing dan release menghasilkan hard reservation.

## Material issue dan BR-042

- Fabric issue wajib menunjuk reservation dan roll yang sama dalam UOM pemakaian material.
- Setiap issue roll menambah `fabric_dispatch_balances.qty_dispatched` untuk MO×roll.
- Marker menambah `qty_consumed`; leftover return menambah `qty_returned`.
- Constraint database menjaga `consumed + returned <= dispatched`.
- Return hanya boleh sekali per MO×roll, wajib kembali ke warehouse asal, dan harus menutup seluruh `dispatched − consumed − returned`.
- Sisa fisik roll tidak langsung dianggap leftover MO; bagian yang belum di-issue tetap sudah berada di stok warehouse sehingga tidak boleh ditambahkan lagi.
- Roll yang sudah ditutup dengan return tidak dapat di-issue ulang ke MO yang sama.
- Backflush tetap delta/idempotent dan memperbarui reservation serta MO allocation.

## Verification

Regression evidence tersedia untuk issue, backflush, dispatch/consume/return, double-return rejection, dan no-double-count stock. Runtime belum dinyatakan hijau sampai lockfile dan CI tersedia.
