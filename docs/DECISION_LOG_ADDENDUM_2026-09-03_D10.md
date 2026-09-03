# DECISION LOG ADDENDUM — D-10 COGS AUTHORITY

> **Decision ID:** DEC-2026-09-03-10  
> **Decision:** A — SHIPMENT-DATE COGS + LATER ACTUAL VARIANCE  
> **Status:** LOCKED  
> **Decision Owner:** Reza Alpian  
> **Decision Date:** 3 September 2026  
> **Source analysis:** `docs/DECISION_D10_COGS_AUTHORITY.md`

## Decision

Base COGS is recognized on Shipment using the sum of valued company-owned ITS `SHIPMENT` ledger `total_cost`. Later actual MO variance attributable to shipped units is recognized through separate append-only variance entries.

## Locked policy

- Base amount equals the valued ITS Shipment inventory cost under BR-107.
- Recognition date is `shipments.ship_date`; period must match that date and be OPEN.
- `SHIPMENT_COGS` debits the configured COGS account and credits the configured FG Inventory account.
- One deterministic base posting is allowed per Shipment; idempotency conflicts fail closed.
- Missing/duplicate ITS Shipment, unvalued ledger lines, invalid/zero amount, invalid source lineage, missing mapping, or invalid/closed period fails closed.
- Buyer-owned stock does not generate company inventory COGS.
- Later actual-cost variance attributable to shipped units posts separately and append-only under D-11.
- Original Shipment ledger and base COGS journal are not edited.

## Rationale

Shipment is the locked FG-out boundary. Valued ITS Shipment cost aligns expense recognition with physical inventory outflow while preserving the D-07 provisional-to-actual convergence model through later variance.

## Impacted modules

Shipment; ITS FG ledger/balance; FG valuation; Actual Cost variance; COGS; GL/account mapping; period close; reversal/cancellation/return; reporting.

## Implementation consequence

This decision does not authorize implementation. A later phase must add valued Shipment guards, `SHIPMENT_COGS` posting, deterministic source keys, variance allocation/event, period validation, reversal/cancellation/return handling, and tests after D-11 is locked.

## Historical-data consequence

- No historical Shipment receives an automatic COGS journal.
- No historical Shipment, ledger, FG balance, cost, or journal is revalued or rewritten.
- Policy applies prospectively after approved implementation cutover.

## Dependencies

```text
D-10 = SHIPMENT_DATE_COGS_PLUS_LATER_ACTUAL_VARIANCE — LOCKED
        ↓
D-11 Reversal/Timing — NEXT / PENDING
```

## Change boundary

```text
Migration: NONE
Source code: NONE
Journal/posting: NONE
Production behavior: NONE
Historical backfill: NONE
Tests: NOT RUN — DOCUMENTATION/BUSINESS RULE REVIEW
```