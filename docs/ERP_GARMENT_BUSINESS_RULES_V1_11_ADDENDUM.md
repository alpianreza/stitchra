# ERP GARMENT BUSINESS RULES — v1.11 ADDENDUM

> **Status:** LOCKED  
> **Effective decision:** DEC-2026-09-03-08  
> **Decision Owner:** Reza Alpian  
> **Decision Date:** 3 September 2026  
> **Scope:** D-09 Cost per PCS Denominator

## BR-106 — FG Received Primary Cost Denominator

**Status:** LOCKED

Official Actual Manufacturing Cost per FG PCS uses cumulative company-owned ITS `PRODUCTION_RECEIPT.qty_in` traceable to the MO.

- Open-MO FG remains provisional standard under BR-105.
- Final actual cost per FG PCS requires complete actual MO cost and a frozen cumulative FG denominator under D-11.
- Missing trace, incomplete cost, unresolved grade/scrap/rework allocation, missing denominator, or inconsistent receipt history fails closed.
- Planned, cut, sewn, QC, packed, and shipped unit-cost views may exist only as clearly labeled analytical KPIs.
- Analytical KPIs are not FG inventory unit cost and cannot drive valuation or COGS.
- Legacy `production_orders.qty_produced` is not a permitted fallback.

## Clarifications

- BR-065 remains the named-measure authority.
- BR-105 uses this denominator for later actual FG variance reconciliation.
- D-08/D-10/D-11 still control Shipment allocation, COGS, timing, and reversal.

## Historical boundary

No historical denominator, unit cost, receipt, Shipment, or journal is backfilled or rewritten. This rule is prospective after approved cutover.

## Implementation boundary

This addendum is governance only. It creates no migration, code, costing record, valuation layer, journal, API/UI change, or production behavior change.