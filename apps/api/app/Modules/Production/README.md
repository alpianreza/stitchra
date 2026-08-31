# Modul Production (Manufacturing Order)

MO dibuat per style dari SO `CONFIRMED`, menyimpan snapshot BOM/routing, dan release menghasilkan hard reservation.

## MO invariants

- SO dikunci saat MO dibuat; hanya SO `CONFIRMED` pada company user yang diterima.
- Satu MO per company×SO×style dilindungi service lock dan unique constraint.
- BOM/routing `APPROVED` disimpan sebagai snapshot version pada MO.
- Release hanya dari `PLANNED`, mengunci MO dan seluruh candidate balances.
- Kebutuhan BOM diagregasi per material sebelum reservation.
- Reservation dialokasikan FIFO ke balance nyata termasuk location, lot, roll, dan ownership.
- Shortage pada satu material membatalkan seluruh release tanpa partial reservation.
- Unrelease mengembalikan reserved pada dimension key yang sama dan ditolak setelah material pernah di-issue.

## Material issue

- Actual fabric issue wajib menunjuk roll reservation yang sama.
- ITS mengurangi on-hand dan reserved pada balance dimension yang sama.
- Backflush memakai target kumulatif `BOM × qty_produced`, memposting delta saja, dan memperbarui reservation serta MO allocation.
- Semua transition mengunci MO/reservation dan memvalidasi company user serta warehouse.

## Open design

BR-042 leftover roll belum dinyatakan selesai. Model perlu memisahkan qty warehouse, qty dispatched ke cutting, qty consumed, dan qty returned; implementasi tanpa empat state tersebut berisiko double-count stock.

## Verification status

Regression tests tersedia untuk snapshot MO, duplicate prevention, hard reservation, shortage rollback, per-roll allocation, exact issue, incremental backflush, dan safe unrelease. Runtime result belum dinyatakan hijau sampai lockfile tersedia dan CI dijalankan.
