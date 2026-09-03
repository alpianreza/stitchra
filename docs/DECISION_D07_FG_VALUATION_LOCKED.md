# STITCHRA — D-07 FINISHED GOODS VALUATION — LOCKED

> **Decision ID:** DEC-2026-09-03-07  
> **Selected option:** C — PROVISIONAL STANDARD FG + ACTUAL VARIANCE  
> **Status:** LOCKED  
> **Decision Owner:** Reza Alpian  
> **Decision Date:** 3 September 2026

## Locked result

```text
D-07_DECISION = PROVISIONAL_STANDARD_FG_PLUS_ACTUAL_VARIANCE
D-07_STATUS = LOCKED
FG_QUANTITY_AUTHORITY = ITS_PRODUCTION_RECEIPT
FG_PROVISIONAL_COST_SOURCE = IMMUTABLE_MO_STANDARD_COST
FG_UNIT_COST_DENOMINATOR = PENDING_D09
PROVISIONAL_STANDARD_IS_FINAL_ACTUAL = NO
ACTUAL_CONVERGENCE = APPEND_ONLY_VARIANCE_REVALUATION
FG_ON_HAND_VS_SHIPPED_ALLOCATION = PENDING_D08_D10
TIMING_PERIOD_REVERSAL = PENDING_D11
HISTORICAL_FG_BACKFILL = PROHIBITED
```

## Resulting Business Rule

BR-105 is LOCKED. FG quantity is received through ITS and valued prospectively at provisional standard transferred from WIP. Actual cost later reconciles through append-only variance/revaluation after the denominator, Shipment, COGS, and timing/reversal decisions are locked.

## Explicit non-decisions

D-07 does not select:

- the cost-per-PCS denominator;
- how variance is split between FG on hand and shipped units;
- COGS amount and posting date;
- late-cost period treatment;
- reversal document design.

These remain D-09, D-08, D-10, and D-11.

## Consequences

### Posting

No Production Receipt valuation or journal is authorized during Business Rule Review.

### Historical data

Existing null-cost FG receipts are not backfilled or revalued automatically.

### Impacted modules

Packing, ITS/FG, MO, WIP, Costing, Shipment, COGS, GL, period close, reporting.

## Dependency hand-off

```text
D-07 — LOCKED
D-09 — NEXT / PENDING BUSINESS DECISION
```

## Change boundary

```text
Migration: NONE
Source code: NONE
API/UI: NONE
Valuation/posting: NONE
Production behavior: NONE
Historical backfill: NONE
Tests: NOT RUN — DOCUMENTATION/BUSINESS RULE REVIEW
```