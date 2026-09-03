# ERP GARMENT BUSINESS RULES — v1.10 ADDENDUM

> **Status:** LOCKED  
> **Effective decision:** DEC-2026-09-03-07  
> **Decision Owner:** Reza Alpian  
> **Decision Date:** 3 September 2026  
> **Scope:** D-07 Finished Goods Valuation

## BR-105 — Provisional Standard FG with Actual Variance Reconciliation

**Status:** LOCKED

Prospective FG received through ITS `PRODUCTION_RECEIPT` is valued provisionally from the immutable MO standard-cost basis transferred from WIP.

- ITS remains FG quantity authority.
- D-09 must explicitly define the cost-per-PCS denominator; missing authority fails closed.
- Provisional standard is not final actual cost.
- Complete actual MO cost later reconciles through append-only variance/revaluation entries.
- FG on-hand versus shipped allocation follows D-08/D-10.
- Event timing, periods, late costs, and reversal follow D-11.
- Buyer-owned stock is excluded from company valuation under BR-001.
- No valuation or journal posting is allowed until dependent controls are complete.

## Clarifications

- BR-005 supplies Moving Average mechanics only after an authoritative cost source exists.
- BR-069 supplies the provisional WIP basis transferred toward FG.
- BR-083 identifies the Shipment/COGS boundary but does not define the amount.

## Historical boundary

No historical FG receipt, balance, cost, Shipment, or journal is backfilled or rewritten. This rule is prospective after approved cutover.

## Implementation boundary

This addendum is governance only. It creates no migration, code, valuation layer, journal, API/UI change, or production behavior change.