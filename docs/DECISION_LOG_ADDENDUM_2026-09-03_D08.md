# DECISION LOG ADDENDUM — D-08 SHIPMENT VALUATION

> **Decision ID:** DEC-2026-09-03-09  
> **Decision:** B — PREVAILING FG MOVING AVERAGE AT SHIPMENT  
> **Status:** LOCKED  
> **Decision Owner:** Reza Alpian  
> **Decision Date:** 3 September 2026  
> **Source analysis:** `docs/DECISION_D08_SHIPMENT_VALUATION.md`

## Decision

ITS `SHIPMENT` consumes the prevailing company-owned FG Moving Average for the exact style, colorway, size, warehouse, ownership, and UOM at posting time.

## Locked policy

- Shipment cost comes from the authoritative FG valuation state under BR-005, not a fresh calculation from Packing, MO standard, or Actual Cost.
- Approved Packing List and its traceable `PRODUCTION_RECEIPT` remain mandatory physical lineage.
- Physical lineage does not authorize specific-identification costing.
- Missing unit cost, invalid ownership/UOM/grade, insufficient stock, or unresolved FG valuation fails closed.
- Buyer-owned stock is excluded from company valuation.
- Late MO actual variance is allocated between FG on hand and already shipped units only under D-10/D-11.
- Original Shipment ledger rows remain append-only; later differences use controlled variance/reversal entries.
- D-08 defines inventory cost removal only. It does not itself authorize a COGS journal.

## Rationale

Moving Average is the locked inventory valuation method and supports identical FG from multiple receipts in one balance. Re-deriving cost from the Shipment's Packing/MO source could diverge from the authoritative FG balance after other receipts or adjustments.

## Impacted modules

Shipment; Packing/Carton; ITS FG ledger/balance; FG valuation; Actual Cost variance; COGS; GL/account mapping; period close; reversal; reporting.

## Implementation consequence

This decision does not authorize implementation. A later phase must define authoritative FG cost-state reads, ownership/UOM/grade dimensions, deterministic Shipment costing, late-variance allocation, COGS posting, periods, reversals, and tests.

## Historical-data consequence

- No historical Shipment or ledger row is backfilled or revalued.
- No stock balance, receipt, cost, or journal is rewritten.
- Policy applies prospectively after approved implementation cutover.

## Dependencies

```text
D-08 = PREVAILING_FG_MOVING_AVERAGE — LOCKED
        ↓
D-10 COGS — NEXT / PENDING
        ↓
D-11 Reversal/Timing
```

## Change boundary

```text
Migration: NONE
Source code: NONE
Valuation/COGS posting: NONE
Production behavior: NONE
Historical backfill: NONE
Tests: NOT RUN — DOCUMENTATION/BUSINESS RULE REVIEW
```