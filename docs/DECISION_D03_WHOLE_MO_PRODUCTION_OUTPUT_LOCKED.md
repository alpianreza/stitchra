# STITCHRA — D-03 WHOLE-MO PRODUCTION OUTPUT AUTHORITY — LOCKED

> **Decision ID:** DEC-2026-09-03-02  
> **Selected option:** E — SEPARATE NAMED MEASURES  
> **Status:** LOCKED  
> **Decision Owner:** Reza Alpian  
> **Decision Date:** 3 September 2026

## Locked result

```text
D-03_DECISION = SEPARATE_NAMED_MEASURES
D-03_STATUS = LOCKED
GENERIC_QTY_PRODUCED_AUTHORITY = NO
PRODUCTION_ORDERS_QTY_PRODUCED = LEGACY_COMPATIBILITY_NON_AUTHORITATIVE
UNIVERSAL_CROSS_STAGE_OUTPUT = PROHIBITED
DOWNSTREAM_CONSUMER_SOURCE = MUST_BE_EXPLICIT
```

## Resulting Business Rule

BR-065 is LOCKED:

- no single generic whole-MO output is authoritative;
- each persisted event is authoritative only for its named stage measure;
- `production_orders.qty_produced` remains legacy and non-authoritative;
- each downstream consumer must explicitly select a named measure through its dependent decision;
- undefined consumers remain blocked rather than using a silent fallback.

## Named boundaries

| Persisted evidence | Locked meaning |
|---|---|
| Cut Output | Cutting output quantity |
| Final Sewing OUT | Sewing output quantity |
| Finishing OUT | Finishing output evidence |
| QC FINAL PASS lot quantity | Quality/Packing eligibility evidence |
| Packing quantity | Packed quantity |
| ITS PRODUCTION_RECEIPT | FG received quantity |
| ITS SHIPMENT | Shipped quantity |
| production_orders.qty_produced | Legacy compatibility; non-authoritative |

## Explicit non-decisions

D-03 does not decide:

- which named measure drives Backflush;
- ACTUAL versus BACKFLUSH material policy;
- actual-cost or cost-per-unit denominator;
- MO completion lifecycle;
- defect/rework/reject reconciliation arithmetic;
- WIP/FG valuation or COGS;
- historical backfill/cutover.

These remain controlled by D-04, D-09, and other dependent decisions.

## Consequences

### Impacted modules

Production Order; Cutting; Shop Floor; Finishing; QC; Packing; ITS; FG/Shipment; Actual Cost; Backflush; reporting.

### Implementation

No implementation is authorized. A later implementation phase must remove silent generic-source assumptions and make each consumer's named source explicit only after dependencies are locked.

### Historical data

No historical value or event is rewritten, backfilled, deleted, recalculated, or reinterpreted.

## Dependency hand-off

```text
D-01 = LAY_ROLL — LOCKED
D-03 = SEPARATE_NAMED_MEASURES — LOCKED
D-04 = NEXT / PENDING BUSINESS DECISION
D-02 = PENDING after D-04
```

## Change boundary

```text
Migration: NONE
Source code: NONE
API/UI: NONE
Production behavior: NONE
Historical rewrite/backfill: NONE
Legacy endpoint removal: NONE
Tests: NOT RUN — DOCUMENTATION/BUSINESS RULE REVIEW
```