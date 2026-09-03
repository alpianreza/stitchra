# STITCHRA — D-11 REVERSAL AND TIMING POLICY — LOCKED

> **Decision ID:** DEC-2026-09-03-11  
> **Selected option:** A — OPEN REPOST, CLOSED ADJUST  
> **Status:** LOCKED  
> **Decision Owner:** Reza Alpian  
> **Decision Date:** 3 September 2026

## Locked result

```text
D-11_DECISION = OPEN_PERIOD_REVERSAL_REPOST_CLOSED_PERIOD_PROSPECTIVE_ADJUSTMENT
D-11_STATUS = LOCKED
OPEN_PERIOD_CORRECTION = APPEND_ONLY_REVERSAL_AND_CORRECTED_REPOST
CLOSED_PERIOD_REOPEN = PROHIBITED_BY_DEFAULT
CLOSED_ORIGINAL_JOURNAL_MUTATION_OR_VOID = PROHIBITED
CLOSED_PERIOD_CORRECTION = SEPARATE_APPROVED_CURRENT_OPEN_PERIOD_ADJUSTMENT
ORIGINAL_AND_ADJUSTMENT_TIMING_EVIDENCE = REQUIRED
OPERATIONAL_REVERSAL_BEFORE_ACCOUNTING = REQUIRED_WHEN_STOCK_OR_QTY_CHANGES
REASON_USER_APPROVAL_AUDIT_MAPPING_IDEMPOTENCY = REQUIRED
MATERIALITY_OR_RETAINED_EARNINGS_INFERENCE = PROHIBITED
HISTORICAL_AUTOMATIC_REWRITE_OR_BACKFILL = PROHIBITED
```

## Resulting Business Rule

BR-109 is LOCKED. Open-period corrections use append-only reversal/repost in the original period. Closed-period corrections preserve the original journal and use a separate approved adjustment in the current OPEN period.

## Explicit boundaries

D-11 does not choose a materiality threshold, retained-earnings account, statutory restatement rule, or detailed operational return schema. These require explicit Finance configuration or a separate approved decision.

## Consequences

### Existing implementation

`JournalService::reverseIntoPeriod()` currently marks the original journal VOID. It is not authorized for the locked closed-period policy until a later implementation prevents mutation of the closed-period original and introduces a separate adjustment event.

### Historical data

No existing row is automatically changed. CLOSED periods stay closed.

### Impacted modules

GL/Journal; Period Close; ITS; Material Issue/Return; WIP/FG; Shipment/returns; Actual Cost; COGS; approvals/audit; reporting.

## Dependency closure

```text
D-01 = LOCKED
D-03 = LOCKED
D-04 = LOCKED
D-02 = LOCKED
D-05 = LOCKED
D-06 = LOCKED
D-07 = LOCKED
D-09 = LOCKED
D-08 = LOCKED
D-10 = LOCKED
D-11 = LOCKED
```

## Change boundary

```text
Migration: NONE
Source code: NONE
API/UI: NONE
Journal/reversal posting: NONE
Production behavior: NONE
Historical backfill: NONE
Tests: NOT RUN — DOCUMENTATION/BUSINESS RULE REVIEW
```