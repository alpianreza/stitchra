# BATCH 3 — D-06 + D-07 EXECUTABLE SPECIFICATION

> Status: LOCKED FOR BATCH 3 IMPLEMENTATION  
> Source: D-01..D-11, BR-065/069/105..109, Decision Completion Round 3, owner mandate 3 Sep 2026  
> Scope: prospective valuation records only; no historical backfill and no GL posting.

## Decision closure

1. WIP valuation is triggered only by `CUTTING→SEWING` and `SEWING→FINISHING` append-only transfers.
2. Existing D-03 measures remain unchanged. `CUT_OUTPUT` validates `CUTTING→SEWING`; `SEWING_FINAL_OUT` validates `SEWING→FINISHING`. Transfer identity is the event source, not a new generic output.
3. Allocation is an approved, effective-dated profile with one explicit cumulative rule for each standard component and transfer boundary. Rules store `allocation_rule`, decimal `allocation_value`, and cumulative semantics. No default percentages exist. The profile is snapshotted on the MO eligibility record at its first prospective valuation authority.
4. WIP is append-only delta valuation. `SEWING→FINISHING` appends Sewing relief and Finishing addition; originals are not changed.
5. Valid zero quantity produces `NO_ELIGIBLE_QUANTITY` and no financial row. Missing quantity/evidence fails closed.
6. Actual cost becomes final only through an explicit approved freeze version. The freeze captures material, labor/CM, overhead, subcontract, other, D-09 denominator, source evidence, standard hash, user, approval, and timestamp.
7. Variance sign is `actual - provisional`, calculated by component using DECIMAL monetary precision and rounded to 4 decimals.
8. An approved freeze version is the variance source document. Identity is company × MO × freeze version × valuation object × component. Same payload replays; changed payload conflicts.
9. FG receives accumulated Finishing WIP value. FG receipt fails closed when sufficient Finishing WIP lineage is absent. WIP relief and FG addition are separate append-only records.
10. Partial FG valuation is cumulative target less prior recognized FG value in ITS receipt chronology. Earlier receipts must be valued first. Rounding uses DECIMAL(19,4); the deterministic final allocation bucket receives any residual.
11. Frozen variance is split proportionally by authoritative quantity state: open WIP, FG on hand, and shipped hand-off. Shipped hand-off is only a D-10/D-11 integration contract; this batch creates no Shipment/COGS mutation or journal.
12. Cutover is an explicit approved MO eligibility marker created after this migration. It binds policy version `D06_D07_V1`, allocation version/hash, MO standard snapshot hash, effective date, and approval. No MO is auto-enrolled.

## Deterministic derivations

- Transfer validation uses cumulative transfer quantity `<=` the corresponding D-03 Named Measure; the measure is semantic validation while `wip_transfers.id` is the event identity.
- Hybrid allocation values are never derived from arbitrary percentages. The approved profile carries the explicit value and references the MO BOM/routing/SAM in its frozen snapshot.
- Actual labor and overhead use locked BR-009 structure and D-09 final output quantity: `FG_RECEIVED_QTY × routing total SAM × applicable period rate`. Missing rate/SAM fails closed when the standard component is non-zero.
- Material actual is valued company-owned ITS `MATERIAL_ISSUE` less `PRODUCTION_RETURN`, separated as Fabric and Trim/other non-fabric material. Missing ledger cost fails closed.
- Subcontract actual is the linked MO subcon fee total. Other actual requires explicit source evidence when the standard Other component is non-zero; otherwise zero is reproducible.
- Quantity-state variance split uses open WIP quantity plus cumulative FG received quantity. FG is split into on-hand and shipped using D-09/D-08 authoritative quantities. Allocation order is WIP, FG_ON_HAND, SHIPPED_HANDOFF; the last non-zero bucket receives rounding residual.

## Boundaries

- ITS remains inventory quantity authority.
- Valuation tables are not stock ledgers or stock balances.
- No movement type is added.
- Original WIP transfers, ITS receipts, Shipments, and journals remain immutable.
- No Production Receipt, Shipment, COGS, or correction journal is created.
- D-08, D-09, D-10, and D-11 implementation remains outside this batch.
