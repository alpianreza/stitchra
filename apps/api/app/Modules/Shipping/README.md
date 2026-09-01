# Modul Shipping

- Satu packing list APPROVED hanya dapat membuat satu shipment.
- Shipment header tidak dapat mengoverride company, SO, PL, status, atau audit fields.
- Tolerance dihitung terhadap projected cumulative shipped per matrix SO.
- Override luar toleransi hanya untuk shipment belum dikirim dan tercatat audit.
- Ship mengunci shipment, packing list, dan SO; warehouse wajib FG pada company yang sama.
- ITS mengeluarkan FG sekali dengan shipment sebagai source id.
- SO CLOSED hanya jika setiap matrix mencapai batas bawah toleransi; total agregat tidak dapat menutupi shortage matrix lain.

Regression tests tersedia, tetapi belum dinyatakan hijau sampai lockfiles dan CI tersedia.
