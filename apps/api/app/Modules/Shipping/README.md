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

## Iteration 14 — Commercial Fulfillment / Delivery Schedule → Shipment

- SO dan `sales_order_lines` tetap commercial order authority; BR-020 mendefinisikan matrix style × colorway × size.
- Shipment quantity tetap berasal dari exact Carton matrix pada satu Packing List APPROVED. Request tidak dapat memasukkan atau mengubah shipment quantity.
- SO Matrix menjadi cumulative commercial ceiling/floor melalui existing BR-021/082 tolerance: SO-level override, lalu buyer shipment tolerance, lalu 0.
- Multiple Packing Lists dan Shipments per SO didukung oleh existing architecture dan cumulative SHIPPED quantities. Satu Packing List tetap hanya dapat memiliki satu Shipment dan partial Carton shipment tidak dibuat.
- `delivery_schedules` hanya memiliki SO, delivery date, aggregate qty, dan destination. Tidak ada matrix line, company column langsung, status, approval, Shipment FK, allocation, atau fulfillment lifecycle.
- Karena itu `DELIVERY_SCHEDULE_SHIPMENT_AUTHORITY`, `DELIVERY_SCHEDULE_LINK`, dan `DELIVERY_SCHEDULE_FULFILLMENT` tetap **NOT DEFINED**. Schedule ditampilkan read-only melalui parent SO dan tidak dipakai mengganti Packing/Carton authority.
- Shipment lifecycle schema adalah DRAFT/READY/SHIPPED/CANCELLED; write path yang terbukti hanya create DRAFT dan DRAFT/READY → SHIPPED. READY transition dan cancellation/reversal tetap **NOT DEFINED**.
- Dedicated PF-09 Commercial Invoice dan export/customs/trade documents belum ada. Existing Finance AR Invoice dari Shipment SHIPPED diklasifikasikan sebagai finance document, bukan substitute Commercial Invoice.
- `SHIPMENT_VALUATION = NOT DEFINED`, `COGS = NOT DEFINED`, dan `SHIPMENT_ACCOUNTING_REVERSAL = NOT DEFINED`. Tidak dibuat valuation, cost-per-unit, COGS journal, stock reversal, atau accounting reversal.
- Read-only APIs:
  - `GET shipping/commercial-fulfillment/authority`
  - `GET shipping/commercial-fulfillment/sales-orders/{salesOrder}`
  - `GET shipping/shipments/{shipment}/commercial-lineage`
- Semua route memakai auth, company middleware, existing `shipping.shipment.view`, tenant-safe binding, explicit user-company access, dan active-company validation.
- Shipping workbench menampilkan authority matrix, quantity candidates, SO Matrix ordered/shipped/remaining/ceiling, Delivery Schedule evidence dengan missing-link marker, Packing/Shipment context, commercial document boundary, dan forward/reverse lineage.
- Migration: **NONE**. Tidak ada parallel fulfillment/shipment ledger, arbitrary Delivery Schedule FK, historical backfill, atau destructive change.

Feature tests Iteration 14 disiapkan di `CommercialFulfillmentBoundaryTest.php`, termasuk matrix authority, schedule-without-allocation, multiple Shipment cumulative fulfillment, Packing/ITS source, no COGS journal, commercial/export document boundary, lineage, dan company isolation.

Runtime verification tetap **DEFERRED — FINAL VERIFICATION PHASE**.
