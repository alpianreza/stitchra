# Modul Subcon / External Production

## Defined authority

- PF-08 defines the existing Job Work Order (`subcon_orders`) as the external-production document linked to Production Order, optional routing operation, and supplier type `SUBCON`.
- BR-090 defines outbound company-owned material and outstanding quantity. Inventory changes remain exclusively in `InventoryTransactionService` through existing `SUBCON_OUT` / `SUBCON_IN`; no parallel ledger or new movement type is introduced.
- BR-091 defines `subcon_fees` as the service-cost boundary consumed by `ActualCostingService` for the related MO.

## Iteration 9 controls

- Supplier must be active, same-company, and type `SUBCON`.
- Source warehouse must be active, same-company, and not `SUBCON_VIRTUAL`; the existing ITS implementation tracks `in_transit_subcon` against the concrete source balance.
- Eligible-material API exposes only active, company-owned, positive-available stock balances.
- A material line can carry a concrete stock-balance source; warehouse, location, lot, roll, ownership, material, and UOM are preserved into the append-only outbound ledger.
- Receipt is capped by outstanding line quantity and is locked to the original `SUBCON_OUT` stock dimensions. This prevents cross-warehouse phantom stock and decrements the same in-transit balance.
- Optional client and receipt references provide additive idempotency without historical backfill. Existing source-document uniqueness still protects one inventory movement per Job Work/receipt source.
- Lineage exposes MO, vendor, operation, material or Bundle, outbound movement, return movements, warehouse, quantities, aging, fees, and explicit authority markers.
- All mutations use DB transactions, row locks, ITS balance locks, tenant validation, lifecycle gates, and append-only audit/ledger behavior.

## Explicitly undefined or incomplete

- Job Work approval lifecycle is specified by PF-08 but the existing status schema jumps from DRAFT to SENT; Iteration 9 does not invent approval configuration or rewrite lifecycle history.
- Vendor process detail, process loss, yield, scrap, reject arithmetic, and claim valuation are **NOT DEFINED**.
- Bundle/WIP movement to vendor is **NOT DEFINED** in the existing ITS dimensions. Bundle lines remain readable but do not create a fake material or internal production scan.
- Vendor return → QC/WIP/Finishing/FG handoff authority is **NOT DEFINED** and no automatic stage transition is created.
- Supplier invoice/AP matching to Job Work is not implemented. `subcon_fees` only feeds actual MO costing.
- Existing virtual-warehouse architecture is not rewritten or backfilled; material round trips preserve the current source-balance `in_transit_subcon` semantics.

Tests are prepared but runtime remains **DEFERRED — FINAL VERIFICATION PHASE**.
