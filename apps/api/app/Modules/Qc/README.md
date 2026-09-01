# Modul QC

- QC stage divalidasi terhadap lifecycle MO: INLINE, ENDLINE, FINAL.
- FINAL hanya untuk MO FINISHING/QC dan PASS memindahkan FINISHING → QC.
- MO serta inspection dikunci; `(MO, stage, cycle)` unik di database.
- Cycle baru hanya boleh setelah cycle sebelumnya REWORK.
- AQL buyer di-snapshot saat create; defect wajib aktif dan berasal dari company yang sama.
- Bundle defect harus milik MO; operation harus ada di routing snapshot.
- Packing membutuhkan FINAL PASS dan status MO QC.

Regression tests tersedia, tetapi belum dinyatakan hijau sampai lockfiles dan CI tersedia.
