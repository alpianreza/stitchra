# Modul Shipping

## Iteration 8 — FG / Warehouse / Shipment

- PF-09 menjadi authority: Packing List APPROVED dengan QC FINAL PASS dan ITS `PRODUCTION_RECEIPT` yang traceable adalah source Shipment.
- `GET shipping/eligible-fg` hanya mengekspos Packing List APPROVED yang belum memiliki Shipment dan memiliki receipt valid.
- Receipt divalidasi ulang terhadap Carton matrix: style × colorway × size dan quantity harus sama.
- Receipt harus berada pada satu warehouse FG aktif milik company yang sama.
- Satu Packing List hanya dapat membuat satu Shipment; unique constraint existing tetap menjadi final DB guard.
- Shipment lines diturunkan dari Carton matrix dan tidak menerima quantity arbitrary dari request.
- Saat ship, warehouse dikunci ke warehouse sumber `PRODUCTION_RECEIPT`; cross-warehouse consumption ditolak.
- Stock availability divalidasi per FG matrix; ITS tetap menjadi authority final dengan balance lock dan non-negative guard.
- `SHIPMENT` hanya diposting melalui ITS dengan Shipment sebagai source document, sehingga duplicate stock OUT idempotent.
- `GET shipping/shipments/{shipment}/lineage` menelusuri Shipment → Packing List/Carton → PRODUCTION_RECEIPT → FG → QC FINAL → MO → SO dan movement SHIPMENT.
- Shipment tetap dibuat secara eksplisit; tidak ada automatic Shipment, movement type baru, parallel FG ledger, reversal otomatis, atau historical backfill.
- `production_orders.qty_produced` tidak diberi writer baru: authority masih **NOT DEFINED**.
- Cancellation/reversal receipt dan Shipment masih **NOT DEFINED** dan tidak diasumsikan.

Regression tests disiapkan di `FgWarehouseShipmentTraceabilityTest.php`. Runtime verification tetap **DEFERRED — FINAL VERIFICATION PHASE**.
