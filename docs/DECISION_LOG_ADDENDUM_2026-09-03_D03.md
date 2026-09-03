# DECISION LOG ADDENDUM — D-03 WHOLE-MO PRODUCTION OUTPUT AUTHORITY

> **Decision ID:** DEC-2026-09-03-02  
> **Decision:** E — SEPARATE NAMED MEASURES  
> **Status:** LOCKED  
> **Decision Owner:** Reza Alpian  
> **Decision Date:** 3 September 2026  
> **Source analysis:** `docs/DECISION_D03_WHOLE_MO_PRODUCTION_OUTPUT.md`

## Decision

Stitchra does not have one universal authoritative whole-MO `qty_produced`.

Each persisted production event is authoritative only for its explicitly named stage measure:

```text
Cut Output                 → cutting output quantity
Final Sewing OUT           → sewing output quantity
Finishing OUT              → finishing output evidence
QC FINAL PASS lot quantity → quality/packing eligibility evidence
Packing quantity           → packed quantity
ITS PRODUCTION_RECEIPT     → FG received quantity
ITS SHIPMENT               → shipped quantity
```

`production_orders.qty_produced` remains **LEGACY COMPATIBILITY DATA — NOT AUTHORITATIVE**. It must not be silently promoted into a cross-stage production authority or used to reinterpret another stage measure.

## Rationale

- The repository persists multiple auditable stage quantities with different lifecycle meanings.
- BR-007, PF-05, PF-06, BR-080, PF-09, and BR-083 define stage boundaries, not one universal quantity.
- No authoritative operational writer or endpoint was found for `production_orders.qty_produced`.
- A single generic quantity would collapse cutting, sewing, finishing, QC, packing, FG, and shipment semantics.
- Separate named measures preserve partial production and prevent silent downstream assumptions.

## Impacted modules

Production Order; Cutting/Cut Output/Bundle; Shop Floor scans and WIP; Finishing; QC/NCR; Packing/Carton; Inventory Transaction Service; FG/Shipment; Actual Cost; Backflush; dashboards/reporting; governance.

## Implementation consequence

This decision does not authorize implementation. In a later implementation phase:

- generic `qty_produced` must remain non-authoritative;
- each consumer must explicitly identify the named measure it uses;
- D-04 must select the Backflush basis and semantics;
- D-09 must select costing denominator(s);
- Packing's legacy `qty_produced` ceiling/status dependency may only be changed after its dependent decisions are locked;
- no new universal aggregate writer may be introduced from this decision.

## Historical-data consequence

- No historical `qty_produced` value is rewritten, backfilled, deleted, recalculated, or reinterpreted.
- No historical Cut Output, Bundle, scan, QC, Packing, ITS receipt, or Shipment row is rewritten.
- Historical compatibility/cutover policy remains governed by its own decision scope.

## Dependencies

```text
D-01 Actual Fabric Consumption — LOCKED: LAY_ROLL
        ↓
D-03 Whole-MO Production Output — LOCKED: SEPARATE_NAMED_MEASURES
        ↓
D-04 ACTUAL vs BACKFLUSH — NEXT / PENDING
        ↓
D-02 Historical Marker/Lay Policy — PENDING after D-04
```

## Change boundary

```text
Migration: NONE
Source code: NONE
API/UI behavior: NONE
Production behavior: NONE
Historical rewrite/backfill: NONE
Legacy endpoint removal: NONE
Tests: NOT RUN — DOCUMENTATION/BUSINESS RULE REVIEW
```