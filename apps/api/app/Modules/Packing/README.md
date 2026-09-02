# Modul Packing

## Iteration 7 invariants

- Packing list tetap menjadi document header dan Carton menjadi container detail; tidak dibuat entity Packing baru.
- Packing Input wajib memiliki source transaction `qc_inspections` dengan `stage=FINAL` dan `verdict=PASS` (BR-080/PF-07).
- Source QC disimpan pada `packing_lists.qc_inspection_id`; kolom nullable menjaga historical rows tanpa backfill.
- Carton hanya dapat ditambah ke Packing List `DRAFT`; Packing List final tidak dimutasi langsung.
- Cumulative carton quantity untuk MO tidak boleh melebihi `QcInspection.lot_qty` dari source FINAL PASS.
- Cumulative matrix style×colorway×size tidak boleh melebihi SO+toleransi (BR-021).
- MO, SO, QC source, Packing List, FG warehouse, dan user wajib berada dalam company scope yang sama.
- MO lock menyerialkan quantity allocation lintas Packing List; sequence Carton tetap dibuat di bawah Packing List lock.
- Carton line quantity positif dan matrix unik tetap dijaga DB constraint.
- Finalize memvalidasi ulang QC source lalu memakai existing ITS `PRODUCTION_RECEIPT` sesuai PF-09/BR-013; source id Packing List menjaga idempotency stock movement.
- Shipment tetap hanya dapat dibuat dari Packing List `APPROVED`, satu shipment per Packing List, dan tidak dibuat otomatis.
- Endpoint `GET packing/eligible-inputs` menampilkan QC source, eligible, packed, dan remaining quantity.
- Endpoint `GET packing/lists/{packingList}/lineage` menampilkan QC→Packing List→Carton→FG receipt→Shipment boundary.

## Undefined authority

- ⚪ NOT DEFINED — direct Bundle atau Finishing Output → Carton allocation.
- ⚪ NOT DEFINED — carton capacity hard limit.
- ⚪ NOT DEFINED — mandatory solid/ratio/mixed instruction schema yang dapat divalidasi penuh.
- ⚪ NOT DEFINED — formal Finishing completion operation dan writer `qty_produced`.

Regression tests dipersiapkan, tetapi runtime tetap **DEFERRED — FINAL VERIFICATION PHASE**.
