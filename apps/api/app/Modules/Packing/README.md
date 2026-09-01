# Modul Packing

- Packing list terikat SO dan optional MO yang sama company/SO.
- Carton sequence dibuat di bawah lock; matrix line unik per carton.
- Setiap style×colorway×size wajib berasal dari matrix SO.
- Finalize mengunci PL, SO, dan MO; warehouse wajib type FG dan PCS UOM wajib milik company.
- Cumulative approved packing tidak boleh melebihi SO+toleransi atau qty produced MO.
- FG receipt diposting sekali melalui ITS menggunakan packing list sebagai source id.
- MO menjadi PACKED hanya saat cumulative packed mencapai qty produced.

Regression tests tersedia, tetapi belum dinyatakan hijau sampai lockfiles dan CI tersedia.
