# DECISION LOG ADDENDUM — D-07 FINISHED GOODS VALUATION

> **Decision ID:** DEC-2026-09-03-07  
> **Decision:** C — PROVISIONAL STANDARD FG + ACTUAL VARIANCE RECONCILIATION  
> **Status:** LOCKED  
> **Decision Owner:** Reza Alpian  
> **Decision Date:** 3 September 2026  
> **Source analysis:** `docs/DECISION_D07_FG_VALUATION.md`

## Decision

Prospective FG received through ITS `PRODUCTION_RECEIPT` is valued provisionally from the immutable MO standard-cost basis transferred from WIP. When actual MO cost becomes complete under later locked denominator and timing rules, FG value converges through append-only variance/revaluation entries rather than editing the original receipt.

## Locked policy

- FG quantity authority remains ITS `PRODUCTION_RECEIPT`.
- Provisional FG cost source is the immutable MO standard-cost basis under BR-100/BR-069.
- The quantity/denominator used for unit cost must be explicitly locked by D-09; missing source fails closed.
- Provisional standard is not final actual cost.
- Actual convergence uses append-only variance/revaluation.
- Allocation between FG on hand and units already shipped follows D-08/D-10.
- Event date, GL period, late-cost treatment, and reversal follow D-11.
- Buyer-owned inventory remains excluded from company valuation under BR-001; any additional ownership workflow requires explicit design.
- No FG valuation or Production Receipt journal may post until all dependent controls are implementation-ready.

## Rationale

This policy continues D-06 consistently, supplies timely value for partial FG receipts, and allows late actual cost to converge without rewriting receipt history.

## Impacted modules

Packing/Carton; ITS/FG ledger and balances; Production Order; WIP valuation; Standard/Actual Cost; Shipment; COGS; GL/account mapping; period close; variance reporting.

## Implementation consequence

This decision does not authorize implementation. A later phase must define receipt cost linkage, D-09 denominator, variance/revaluation document and posting, Moving Average treatment, Shipment allocation, accounting period, and reversal controls.

## Historical-data consequence

- No existing null-cost FG receipt is backfilled or automatically revalued.
- No Packing, ledger, balance, Shipment, or journal row is rewritten.
- Policy applies prospectively after an explicitly approved cutover.

## Dependencies

```text
D-07 = PROVISIONAL_STANDARD_FG_PLUS_ACTUAL_VARIANCE — LOCKED
        ↓
D-09 Cost per PCS — NEXT / PENDING
        ↓
D-08 Shipment Valuation
        ↓
D-10 COGS
        ↓
D-11 Reversal/Timing
```

## Change boundary

```text
Migration: NONE
Source code: NONE
Valuation/posting: NONE
Production behavior: NONE
Historical backfill: NONE
Tests: NOT RUN — DOCUMENTATION/BUSINESS RULE REVIEW
```