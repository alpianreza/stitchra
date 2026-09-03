# ERP GARMENT BUSINESS RULES — v1.12 ADDENDUM

> **Status:** LOCKED  
> **Effective decision:** DEC-2026-09-03-09  
> **Decision Owner:** Reza Alpian  
> **Decision Date:** 3 September 2026  
> **Scope:** D-08 Shipment Valuation

## BR-107 — Shipment Consumes Prevailing FG Moving Average

**Status:** LOCKED

ITS `SHIPMENT` uses the prevailing company-owned FG Moving Average for the exact style/colorway/size, warehouse, ownership, and UOM at posting time.

- Cost is consumed from the authoritative FG valuation state.
- Packing List and `PRODUCTION_RECEIPT` remain mandatory physical lineage but do not authorize specific-identification costing.
- Missing cost, invalid dimensions, insufficient stock, or unresolved valuation fails closed.
- Buyer-owned stock is excluded from company valuation.
- Late actual variance allocation follows D-10/D-11 through append-only entries.
- This rule defines inventory cost removal and does not alone authorize COGS posting.

## Clarifications

- BR-005 is the cost-flow authority.
- BR-105 supplies provisional FG value and later actual variance.
- BR-106 supplies the actual cost-per-FG-PCS denominator.
- BR-083 remains the Shipment/COGS boundary; D-10 must lock the journal amount and recognition rule.

## Historical boundary

No historical Shipment, receipt, stock ledger, balance, cost, or journal is backfilled or rewritten. This rule is prospective after approved cutover.

## Implementation boundary

This addendum is governance only. It creates no migration, code, valuation layer, COGS journal, API/UI change, or production behavior change.