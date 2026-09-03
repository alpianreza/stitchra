# STITCHRA — D-09 COST PER PCS DENOMINATOR — LOCKED

> **Decision ID:** DEC-2026-09-03-08  
> **Selected option:** D — FG RECEIVED PRIMARY + LABELED NAMED KPIs  
> **Status:** LOCKED  
> **Decision Owner:** Reza Alpian  
> **Decision Date:** 3 September 2026

## Locked result

```text
D-09_DECISION = FG_RECEIVED_PRIMARY_PLUS_LABELED_NAMED_KPIS
D-09_STATUS = LOCKED
OFFICIAL_ACTUAL_COST_PER_FG_PCS_DENOMINATOR = COMPANY_OWNED_ITS_PRODUCTION_RECEIPT_QTY
OPEN_MO_UNIT_COST = PROVISIONAL_STANDARD
FINAL_ACTUAL_UNIT_COST = AFTER_FROZEN_COST_AND_DENOMINATOR
OTHER_STAGE_DENOMINATORS = LABELED_ANALYTICAL_KPI_ONLY
LEGACY_QTY_PRODUCED_FALLBACK = PROHIBITED
INCOMPLETE_TRACE_COST_OR_GRADE_ALLOCATION = FAIL_CLOSED
HISTORICAL_DENOMINATOR_BACKFILL = PROHIBITED
```

## Resulting Business Rule

BR-106 is LOCKED. The official accounting denominator is cumulative company-owned FG received quantity traceable to the MO. Other named stage denominators remain analytical labels only and cannot drive FG valuation or COGS.

## Explicit non-decisions

D-09 does not define:

- Shipment valuation layer consumption;
- COGS amount/timing;
- allocation of later variance between FG on hand and shipped units;
- MO close, late-cost, period, or reversal mechanics;
- detailed grade/scrap/rework allocation.

These remain D-08, D-10, D-11, or a separately approved allocation rule.

## Consequences

### Open production

Provisional standard continues under D-07. Final actual unit cost is blocked until cost and denominator are frozen.

### Historical data

No historical denominator or unit cost is created or changed.

### Impacted modules

Costing, MO close, Packing, ITS FG, Shipment, COGS, GL, reporting/UI.

## Dependency hand-off

```text
D-09 — LOCKED
D-08 — NEXT / PENDING BUSINESS DECISION
```

## Change boundary

```text
Migration: NONE
Source code: NONE
API/UI: NONE
Costing/valuation/posting: NONE
Production behavior: NONE
Historical backfill: NONE
Tests: NOT RUN — DOCUMENTATION/BUSINESS RULE REVIEW
```