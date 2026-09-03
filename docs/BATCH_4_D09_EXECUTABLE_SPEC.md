# BATCH 4 — D-09 EXECUTABLE SPECIFICATION

Status: LOCKED FOR IMPLEMENTATION  
Policy: `FG_RECEIVED_PRIMARY_PLUS_LABELED_NAMED_KPIS`

## Authority

- The sole final actual FG denominator is cumulative company-owned ITS `PRODUCTION_RECEIPT` quantity traceable through Packing List to the MO.
- `qty_produced`, packing, QC, cutting, sewing, finishing, and shipment quantities are never fallback denominators.
- Formula: `actual FG total / FG received quantity`, rounded to 4 decimals.
- Standard cost remains the immutable MO standard-cost snapshot. It is displayed separately and is never an actual-cost fallback.

## Components and completeness

The costing version always contains Fabric, Trim, Labor/CM, Overhead, Subcon, and Other. Each is classified `COMPLETE`, `NOT_APPLICABLE`, `MISSING`, or `CONFLICT`; only the first two permit persistence.

- Fabric/Trim consume company-owned ITS `MATERIAL_ISSUE - PRODUCTION_RETURN` valued ledger rows. A zero standard component with no actual rows is `NOT_APPLICABLE`; otherwise missing or unvalued evidence fails closed.
- Labor/CM uses D-09 FG received output × routing SAM × effective line cost rate, with WIP-transfer/scan lineage required.
- Overhead uses D-09 FG received output × routing SAM × effective overhead rate, with the same operational lineage requirement.
- Subcon consumes linked `subcon_fees`; when standard Subcon is non-zero, absent fee evidence fails closed.
- Other is `NOT_APPLICABLE` only when immutable standard Other is zero. No arbitrary Other amount is accepted; non-zero standard Other without an approved actual source fails closed.

## Version, freeze, and variance

- Identity: company × MO × `FG_ACTUAL` × costing version.
- The source hash binds standard hash, receipt/ledger identities, actual-source identities, period, component values, and D-06/D-07 provisional references.
- Same source state returns the existing costing. Changed source state creates a new immutable version.
- The costing payload creates the existing `ACTUAL_COST_FREEZE` document and approval request; no second freeze authority exists.
- Finalization delegates to the existing D-06/D-07 freeze/variance service. Original WIP and FG valuation events remain unchanged.
- D-08, D-10, D-11, Shipment, COGS, and GL posting are outside this batch.

## Cutover

Only MOs with an approved prospective `D06_D07_V1` eligibility record can be costed. No historical row is calculated or backfilled by migration.
