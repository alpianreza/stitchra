# STITCHRA — D-08 SHIPMENT VALUATION — LOCKED

> **Decision ID:** DEC-2026-09-03-09  
> **Selected option:** B — PREVAILING FG MOVING AVERAGE  
> **Status:** LOCKED  
> **Decision Owner:** Reza Alpian  
> **Decision Date:** 3 September 2026

## Locked result

```text
D-08_DECISION = PREVAILING_FG_MOVING_AVERAGE
D-08_STATUS = LOCKED
SHIPMENT_COST_SOURCE = AUTHORITATIVE_FG_VALUATION_STATE
COST_FLOW_METHOD = MOVING_AVERAGE
PACKING_RECEIPT_LINK = MANDATORY_PHYSICAL_LINEAGE
SPECIFIC_IDENTIFICATION_FROM_PACKING = NOT_AUTHORIZED
MISSING_COST_OR_DIMENSION = FAIL_CLOSED
LATE_ACTUAL_VARIANCE = PENDING_D10_D11_APPEND_ONLY
COGS_JOURNAL_AUTHORITY = NOT_GRANTED_BY_D08
HISTORICAL_SHIPMENT_REVALUATION = PROHIBITED
```

## Resulting Business Rule

BR-107 is LOCKED. Shipment consumes prevailing company-owned FG Moving Average from the authoritative inventory valuation state. Physical Packing/receipt lineage remains mandatory but does not replace Moving Average with receipt-specific cost.

## Explicit non-decisions

D-08 does not define:

- COGS journal amount, date, or period;
- allocation of late actual variance to COGS versus FG on hand;
- Shipment cancellation/reversal documents;
- closed-period or late-cost treatment.

These remain D-10 and D-11.

## Consequences

### Posting

No Shipment valuation or COGS journal implementation is authorized during Business Rule Review.

### Historical data

No historical Shipment or ledger cost is changed.

### Impacted modules

Shipment, Packing, ITS/FG, Costing, COGS, GL, period close, reversal, reporting.

## Dependency hand-off

```text
D-08 — LOCKED
D-10 — NEXT / PENDING BUSINESS DECISION
```

## Change boundary

```text
Migration: NONE
Source code: NONE
API/UI: NONE
Valuation/COGS posting: NONE
Production behavior: NONE
Historical backfill: NONE
Tests: NOT RUN — DOCUMENTATION/BUSINESS RULE REVIEW
```