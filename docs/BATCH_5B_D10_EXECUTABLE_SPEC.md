# BATCH 5B — D-10 SHIPMENT COGS

Status: LOCKED FOR IMPLEMENTATION  
Policy: `SHIPMENT_DATE_COGS_PLUS_LATER_ACTUAL_VARIANCE`

## Authority

Base COGS is copied exactly from immutable D-08 `shipment_inventory_cost` for every Shipment Line. D-10 never recalculates moving average, quantity × cost, D-07 provisional value, D-09 actual cost, or standard cost.

## Posting

One deterministic D-10 document is posted per company × Shipment × `SHIPMENT_COGS`. Each source line retains its D-08 valuation, Shipment Line, ITS Shipment movement/ledger, Production Receipt, quantity, unit cost, exact D-08 amount, version, and source hash.

The existing `SHIPMENT_COGS` account mapping supplies the debit and credit accounts. Missing, cross-company, inactive, or identical mapped accounts fail closed. The existing JournalService posts AUTO journal lines at `shipments.ship_date` in the matching GL period. Closed periods fail closed and are never reopened.

For nonzero lines the journal has a debit COGS and credit FG Inventory line carrying the Shipment Line and D-08 valuation IDs in its memo. A legitimate all-zero D-08 source is persisted as `ZERO_COST` after the same mapping and period validation; no illegal zero debit/credit journal line is created.

## Idempotency and correction boundary

The source hash binds company, Shipment, every D-08 identity/amount/unit cost/source hash/version, ITS identities, posting date, currency, and mapping identity. Same source returns the existing COGS result. Changed source conflicts. Original D-08, Shipment, ITS, D-10 document, and posted journal are not mutated.

D-11 receives the original journal (or explicit zero-cost recognition), Shipment, D-08 lines, period, date, currency, posting key, and source hashes. Reversal, corrected repost, late variance, and closed-period adjustment are not implemented here.

Historical backfill: NONE.
