# ERP GARMENT BUSINESS RULES — v1.9 ADDENDUM

> **Status:** LOCKED  
> **Effective decision:** DEC-2026-09-03-06  
> **Decision Owner:** Reza Alpian  
> **Decision Date:** 3 September 2026  
> **Scope:** D-06 WIP Valuation

## BR-069 — Provisional Standard WIP with Actual Variance Reconciliation

**Status:** LOCKED

Prospective open WIP is valued provisionally using the immutable MO standard-cost snapshot.

- WIP quantity uses an explicit named production/WIP measure under BR-065.
- Stage allocation is configured and snapshotted; incomplete configuration fails closed.
- Provisional standard value is not final actual cost.
- Actual material, labor, overhead, and subcon evidence is reconciled later through explicit variance entries at a separately locked completion/FG boundary.
- Valuation records, variance, and corrections are append-only and approval/reversal controlled.
- Posting remains blocked until amount, event timing, period, mapping, idempotency, and reversal rules are complete.

## Clarifications

- BR-064 remains operational WIP quantity lineage; it does not itself create value.
- BR-100 supplies the immutable standard source.
- BR-009 supplies actual-cost components for later reconciliation, subject to the locked denominator and downstream valuation decisions.

## Historical boundary

No historical WIP, scan, transfer, material ledger, cost, or journal value is backfilled or rewritten. This rule is prospective after a separately approved cutover.

## Implementation boundary

This addendum is governance only. It creates no migration, code, valuation layer, journal, API/UI change, or production behavior change.