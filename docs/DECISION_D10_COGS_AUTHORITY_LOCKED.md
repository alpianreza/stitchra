# STITCHRA — D-10 COGS AUTHORITY — LOCKED

> **Decision ID:** DEC-2026-09-03-10  
> **Selected option:** A — SHIPMENT-DATE COGS + LATER ACTUAL VARIANCE  
> **Status:** LOCKED  
> **Decision Owner:** Reza Alpian  
> **Decision Date:** 3 September 2026

## Locked result

```text
D-10_DECISION = SHIPMENT_DATE_COGS_PLUS_LATER_ACTUAL_VARIANCE
D-10_STATUS = LOCKED
BASE_COGS_AMOUNT = SUM_VALUED_COMPANY_ITS_SHIPMENT_TOTAL_COST
BASE_COGS_DATE = SHIPMENTS_SHIP_DATE
BASE_COGS_PERIOD = MATCHING_OPEN_GL_PERIOD
BASE_COGS_ENTRY = DEBIT_COGS_CREDIT_FG_INVENTORY
BASE_COGS_IDEMPOTENCY = ONE_DETERMINISTIC_POST_PER_SHIPMENT
LATE_ACTUAL_VARIANCE = SEPARATE_APPEND_ONLY_EVENT_PENDING_D11
ORIGINAL_SHIPMENT_AND_BASE_JOURNAL_MUTATION = PROHIBITED
HISTORICAL_COGS_AUTO_POST_OR_BACKFILL = PROHIBITED
```

## Resulting Business Rule

BR-108 is LOCKED. Shipment-date COGS uses valued ITS Shipment cost. Later actual variance attributable to shipped units is separate and append-only; original Shipment and base COGS records are immutable.

## Explicit non-decisions

D-10 does not define:

- late-cost posting period;
- open versus closed original-period correction;
- reversal/corrected repost mechanics;
- Shipment cancellation and customer return timing;
- variance event/source document structure.

These remain D-11.

## Consequences

### Posting

No COGS posting implementation is authorized during Business Rule Review.

### Historical data

No historical Shipment receives automatic COGS or revaluation.

### Impacted modules

Shipment, ITS/FG, Costing, COGS, GL, period close, reversal, reporting.

## Dependency hand-off

```text
D-10 — LOCKED
D-11 — NEXT / PENDING BUSINESS DECISION
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