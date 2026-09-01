# Modul Subcon (CMT)

- Supplier wajib aktif pada company yang sama dan type SUBCON.
- MO, operation routing, warehouse, material/UOM, dan bundle divalidasi tenant-safe.
- Setiap line harus berisi tepat satu material atau bundle dengan qty positif.
- Send dikunci dan memposting SUBCON_OUT melalui ITS.
- Receive mengunci order/line, menolak duplicate/over-return, dan memvalidasi warehouse.
- Setiap partial-return line membuat fee record sendiri; fee id menjadi source id SUBCON_IN sehingga return berikutnya tidak bentrok dengan idempotency ITS.
- Database check menjaga qty returned tidak negatif atau melebihi qty sent.

Regression tests tersedia, tetapi belum dinyatakan hijau sampai lockfiles dan CI tersedia.
