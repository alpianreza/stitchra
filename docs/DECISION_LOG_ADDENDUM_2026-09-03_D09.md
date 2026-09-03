# DECISION LOG ADDENDUM — D-09 COST PER PCS DENOMINATOR

> **Decision ID:** DEC-2026-09-03-08  
> **Decision:** D — FG RECEIVED PRIMARY + LABELED NAMED KPIs  
> **Status:** LOCKED  
> **Decision Owner:** Reza Alpian  
> **Decision Date:** 3 September 2026  
> **Source analysis:** `docs/DECISION_D09_COST_PER_PCS_DENOMINATOR.md`

## Decision

The official denominator for Actual Manufacturing Cost per FG PCS is cumulative company-owned ITS `PRODUCTION_RECEIPT.qty_in` traceable to the MO.

## Locked policy

- FG received quantity is the primary accounting denominator.
- Only company-owned FG participates in company inventory valuation.
- During an open MO, FG remains at D-07 provisional standard.
- Final actual cost per FG PCS is recognized only after complete actual MO cost and the cumulative FG denominator are frozen under D-11 close/timing rules.
- Missing MO trace, incomplete cost, unresolved grade/scrap/rework allocation, missing denominator, or inconsistent receipt history fails closed.
- Planned, cut, sewn, QC, packed, and shipped denominators may be reported only as explicitly labeled analytical KPIs.
- Those analytical KPIs are not FG inventory unit cost and must not drive valuation or COGS.
- Legacy `production_orders.qty_produced` is prohibited as a generic fallback.

## Rationale

ITS `PRODUCTION_RECEIPT` is the defined FG quantity authority and directly supports D-07 FG valuation convergence. Named analytical KPIs preserve D-03 without reintroducing a universal output.

## Impacted modules

Actual/Standard Cost; MO close; Packing/Carton; ITS FG receipt; WIP/FG valuation; Shipment; COGS; variance/revaluation; GL; reporting/UI.

## Implementation consequence

This decision does not authorize implementation. A later phase must define denominator snapshots, MO/receipt aggregation, grade allocation, provisional/final states, actual variance, close/period/reversal controls, and labeled KPI reporting.

## Historical-data consequence

- No historical denominator or unit cost is backfilled.
- No cost, output, Packing, FG receipt, Shipment, or journal row is rewritten.
- The policy applies prospectively after approved implementation cutover.

## Dependencies

```text
D-09 = FG_RECEIVED_PRIMARY_PLUS_LABELED_NAMED_KPIS — LOCKED
        ↓
D-08 Shipment Valuation — NEXT / PENDING
        ↓
D-10 COGS
        ↓
D-11 Reversal/Timing
```

## Change boundary

```text
Migration: NONE
Source code: NONE
Costing/valuation/posting: NONE
Production behavior: NONE
Historical backfill: NONE
Tests: NOT RUN — DOCUMENTATION/BUSINESS RULE REVIEW
```