# ERP GARMENT BUSINESS RULES — v1.13 ADDENDUM

> **Status:** LOCKED  
> **Effective decision:** DEC-2026-09-03-10  
> **Decision Owner:** Reza Alpian  
> **Decision Date:** 3 September 2026  
> **Scope:** D-10 COGS Authority

## BR-108 — Shipment-Date COGS with Later Actual Variance

**Status:** LOCKED

Base COGS is recognized at Shipment from the sum of valued company-owned ITS `SHIPMENT` ledger `total_cost`.

- Recognition date is `shipments.ship_date` in the matching OPEN GL period.
- `SHIPMENT_COGS` debits configured COGS and credits configured FG Inventory.
- Exactly one deterministic base posting is allowed per Shipment.
- Missing source, duplicate movement, unvalued line, buyer ownership, invalid amount/lineage/mapping/period, or idempotency conflict fails closed.
- Later actual MO variance attributable to shipped units uses a separate append-only event under D-11.
- Original Shipment ledger and base COGS journal remain immutable.

## Clarifications

- BR-107 supplies the Shipment inventory cost amount.
- BR-105/106 supply provisional-to-actual FG convergence and its denominator.
- BR-083 remains the operational Shipment/COGS boundary.

## Historical boundary

No historical Shipment, ledger, balance, cost, or journal is automatically posted, revalued, backfilled, or rewritten. This rule is prospective after approved cutover.

## Implementation boundary

This addendum is governance only. It creates no migration, code, COGS journal, variance entry, API/UI change, or production behavior change.