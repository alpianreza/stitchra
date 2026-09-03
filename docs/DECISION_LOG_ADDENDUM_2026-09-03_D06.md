# DECISION LOG ADDENDUM — D-06 WIP VALUATION

> **Decision ID:** DEC-2026-09-03-06  
> **Decision:** D — PROVISIONAL STANDARD WIP + ACTUAL VARIANCE RECONCILIATION  
> **Status:** LOCKED  
> **Decision Owner:** Reza Alpian  
> **Decision Date:** 3 September 2026  
> **Source analysis:** `docs/DECISION_D06_WIP_VALUATION.md`

## Decision

Prospective open WIP is valued provisionally from the immutable approved standard-cost snapshot attached to the MO. Actual material, labor, overhead, and subcon cost is reconciled later through explicit variance entries at a separately locked completion/FG boundary.

## Locked policy

- WIP quantity and WIP value remain separate authorities.
- Quantity must come from an explicit named production/WIP measure under BR-065.
- Standard value must come from the immutable MO standard-cost snapshot under BR-100.
- Stage allocation must be explicitly configured and snapshotted; missing allocation or quantity source fails closed.
- Provisional value must not be presented as final actual cost.
- Actual reconciliation requires complete approved cost evidence and the dependent FG, denominator, COGS, period, and reversal decisions.
- Valuation and corrections are append-only; reversal/adjustment follows BR-013/017 and D-11.
- No production/WIP journal may post until amount, event date, period, account mapping, idempotency, and reversal controls are implementation-ready.

## Rationale

The standard-cost snapshot is stable before MO release, while actual material/labor/overhead/subcon evidence develops over time and can arrive late. Provisional standard WIP provides timely valuation without claiming incomplete actual cost; later variance preserves BR-009/100 comparison.

## Impacted modules

Inventory/ITS; Material Issue/Return; Production Order; Cutting/Bundle; Shop Floor scans/WIP transfers; QC/Rework; Subcon; Standard/Actual Cost; Packing/FG; GL/account mapping; period close; reporting.

## Implementation consequence

This decision does not authorize implementation. A later phase must define stage-allocation profiles, named quantity links, valuation layers, accounting events, deterministic posting keys, variance journals, late-cost handling, and reversal/adjustment controls after dependent decisions are locked.

## Historical-data consequence

- No historical WIP value is backfilled.
- No scan, transfer, material ledger, cost, or journal row is rewritten.
- The policy applies prospectively only after an explicitly approved implementation cutover.

## Dependencies

```text
D-06 = PROVISIONAL_STANDARD_WIP_PLUS_ACTUAL_VARIANCE — LOCKED
        ↓
D-07 FG Valuation — NEXT / PENDING
        ↓
D-09 Cost per PCS
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
Journal/posting: NONE
Production behavior: NONE
Historical backfill: NONE
Tests: NOT RUN — DOCUMENTATION/BUSINESS RULE REVIEW
```