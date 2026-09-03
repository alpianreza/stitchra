# STITCHRA — D-06 WIP VALUATION — LOCKED

> **Decision ID:** DEC-2026-09-03-06  
> **Selected option:** D — PROVISIONAL STANDARD WIP + ACTUAL VARIANCE  
> **Status:** LOCKED  
> **Decision Owner:** Reza Alpian  
> **Decision Date:** 3 September 2026

## Locked result

```text
D-06_DECISION = PROVISIONAL_STANDARD_WIP_PLUS_ACTUAL_VARIANCE
D-06_STATUS = LOCKED
OPEN_WIP_VALUE_SOURCE = IMMUTABLE_MO_STANDARD_COST_SNAPSHOT
WIP_QUANTITY_SOURCE = EXPLICIT_NAMED_MEASURE
STAGE_ALLOCATION = CONFIGURED_AND_SNAPSHOTTED
MISSING_QUANTITY_OR_ALLOCATION = FAIL_CLOSED
PROVISIONAL_STANDARD_IS_FINAL_ACTUAL = NO
ACTUAL_RECONCILIATION = EXPLICIT_APPEND_ONLY_VARIANCE
HISTORICAL_WIP_BACKFILL = PROHIBITED
```

## Resulting Business Rule

BR-069 is LOCKED. Open WIP is valued prospectively at provisional standard, using explicit named quantity and a configured/snapshotted stage-allocation profile. Actual cost reconciles later through append-only variance after dependent authority decisions are locked.

## Explicit non-decisions

D-06 does not define:

- stage allocation percentages or component timing;
- FG valuation transfer mechanics;
- cost-per-PCS denominator;
- shipment valuation;
- COGS amount/timing;
- late-cost treatment;
- reversal document design.

These remain controlled by D-07, D-09, D-08, D-10, and D-11.

## Consequences

### Posting

No Material Issue→WIP or WIP-stage journal is authorized during review. Posting remains blocked until implementation controls are complete.

### Historical data

No historical WIP value or journal is created. Existing operational quantity history remains unchanged.

### Impacted modules

ITS; Material Issue/Return; MO; Cutting; Shop Floor/WIP; QC/Rework; Subcon; Costing; Packing/FG; GL; reporting.

## Dependency hand-off

```text
D-06 — LOCKED
D-07 — NEXT / PENDING BUSINESS DECISION
```

## Change boundary

```text
Migration: NONE
Source code: NONE
API/UI: NONE
Journal/posting: NONE
Production behavior: NONE
Historical backfill: NONE
Tests: NOT RUN — DOCUMENTATION/BUSINESS RULE REVIEW
```