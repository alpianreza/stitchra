# BATCH 5A — D-08 SHIPMENT INVENTORY VALUATION

Status: LOCKED FOR IMPLEMENTATION  
Policy: `PREVAILING_FG_MOVING_AVERAGE`

## Authority and event

The valuation event is the existing authorized Shipment `ship` boundary. Inside one database transaction the workflow:

1. locks the Shipment and Packing List lineage;
2. locks each applicable ITS stock-balance serialization key and FG balance row;
3. reads the nullable `stock_balances.avg_cost` before OUT;
4. validates company-owned FG availability and source lineage;
5. executes existing `InventoryTransactionService::post('SHIPMENT', ...)` through `ShipmentService`;
6. values the newly-created ITS Shipment ledger row using the captured pre-OUT moving average;
7. persists an immutable per-line D-08 snapshot; and
8. commits all effects together.

`avg_cost = NULL` is missing evidence and fails closed. `avg_cost = 0` is a legitimate zero-cost inventory state. Negative cost conflicts.

## Formula

`shipment quantity × prevailing pre-OUT FG moving average = shipment inventory cost`, rounded to 4 decimals. Unit cost is persisted at 6 decimals.

## Identity and lineage

Identity is company × shipment × shipment line × `ITS_SHIPMENT_OUT`. The source hash binds Shipment/line, FG dimension, quantity, stock-balance identity/state, pre-OUT on-hand, pre-OUT moving average, Packing List, Production Receipt, ITS Shipment movement, and valuation event/version.

Physical lineage is Packing List → MO → company-owned ITS Production Receipt → FG balance → ITS Shipment. Packing/receipt-specific cost is not used; lineage does not replace moving average.

## Boundaries

- ITS remains quantity and stock authority; no movement type or stock balance is added.
- D-07 provisional FG and D-09 actual FG cost are not Shipment cost sources.
- Existing Shipment quantity, tolerance, status, and warehouse rules remain unchanged.
- Original snapshots are immutable. No historical Shipment receives a valuation automatically.
- Cancellation/accounting correction remains deferred to D-11.
- The snapshot and valued ITS Shipment ledger are the D-10 source contract; no COGS or GL entry is created here.
