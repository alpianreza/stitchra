# Modul Receiving & Inward QC

## Fabric length UOM

- Fabric roll dapat memakai `MTR`/`METER` atau `YRD`/`YDS`/`YARD` sebagai UOM pemakaian material.
- Konversi standar: `1 YRD = 0.9144 MTR`.
- PO tetap disimpan dalam buy UOM; roll menyimpan buy quantity, use quantity, conversion rate, dan ekuivalen meter.
- Bila buy UOM adalah KG, GSM dan width dapat digunakan untuk menghasilkan meter lalu dikonversi ke use UOM.
- ITS, quality hold, release, issue, consumption, dan return selalu memakai `use_uom_id` roll/material.
- Unit cost stok dihitung per use UOM sehingga nilai PO tidak berubah akibat konversi.

## Invariants

GR/PO/roll dikunci dan tenant-scoped; over-receipt ditolak; fabric wajib per roll; inward QC dan supplier return mengambil dimensi stok dari server.

Runtime belum dinyatakan hijau sampai lockfile dan CI tersedia.
