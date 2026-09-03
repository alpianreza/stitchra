# ERP GARMENT BUSINESS RULES — v1.5 ADDENDUM

> **Status:** LOCKED  
> **Effective decision:** DEC-2026-09-03-02  
> **Decision Owner:** Reza Alpian  
> **Decision Date:** 3 September 2026  
> **Scope:** D-03 Whole-MO Production Output Authority

## BR-065 — Named Production Output Measures

**Status:** LOCKED

Stitchra has no single generic authoritative whole-MO `qty_produced`.

Each persisted event is authoritative only for its named stage:

- Cut Output for cutting output quantity;
- Final Sewing OUT for sewing output quantity;
- Finishing OUT for finishing output evidence;
- QC FINAL PASS lot quantity for quality and Packing eligibility evidence;
- Packing quantity for packed quantity;
- ITS `PRODUCTION_RECEIPT` for FG received quantity;
- ITS `SHIPMENT` for shipped quantity.

`production_orders.qty_produced` is legacy compatibility data and is not authoritative. It must not be silently used as a universal bridge among these stages.

Every downstream consumer must explicitly name the stage measure it consumes. Where that selection is still undecided, the consumer remains blocked rather than falling back silently:

- Backflush basis: pending D-04;
- Actual-cost/per-unit denominator: pending D-09;
- historical compatibility/cutover: pending its applicable decision.

## Clarifications to existing rules

- **BR-007:** remains Sewing-output authority only; it is not universal whole-MO output.
- **BR-009:** the term `output` does not select a denominator or stage source; costing source remains pending D-09.
- **BR-080:** QC FINAL PASS remains Packing eligibility authority only.
- **BR-083/PF-09:** FG receipt and Shipment remain inventory/shipping quantity boundaries only.

## Historical boundary

This addendum does not rewrite, backfill, delete, recalculate, or reinterpret historical `qty_produced`, Cut Output, Bundle, scan, QC, Packing, FG receipt, or Shipment records.

## Implementation boundary

This addendum is governance only. It creates no migration, source-code change, API/UI change, production behavior change, or legacy-path removal.