# ERP GARMENT BUSINESS RULES — v1.6 ADDENDUM

> **Status:** LOCKED  
> **Effective decision:** DEC-2026-09-03-03  
> **Decision Owner:** Reza Alpian  
> **Decision Date:** 3 September 2026  
> **Scope:** D-04 ACTUAL vs BACKFLUSH Semantics

## BR-066 — Exclusive Material Consumption Method and Named-Stage Backflush

**Status:** LOCKED

For one MO/material, `ACTUAL` and `BACKFLUSH` are mutually exclusive.

### Roll-tracked fabric

- Inventory issue/dispatch uses ACTUAL Material Issue through ITS.
- Physical actual consumption uses `LAY_ROLL` under D-01.
- Fabric Backflush is prohibited.

### Eligible non-fabric material

- The material-class policy permitted by BR-041 selects `ACTUAL` or `BACKFLUSH` and is snapshotted for the MO/material.
- A Backflush material must explicitly identify one authoritative named stage measure under BR-065.
- Missing method, source, quantity, or canonical UOM conversion fails closed.

### Posting rule

Backflush is cumulative and delta-based:

```text
cumulative target = locked BOM consumption basis × authoritative named-stage quantity
posting delta      = cumulative target − prior BACKFLUSH postings
```

ACTUAL quantity is not silently offset because overlap is prohibited. Inventory movement remains ITS-only and append-only under BR-013. Corrections require approved adjustment/reversal under BR-017 and the later reversal decision.

## Clarification to BR-041

BR-041's hybrid capability is retained but clarified:

- fabric is ACTUAL/Lay Roll only;
- eligible low-value non-fabric material may use Backflush;
- the method is exclusive per MO/material;
- Backflush may not use generic `production_orders.qty_produced`;
- an authoritative named stage measure is mandatory.

## Historical boundary

No existing Material Issue, ledger, reservation, allocation, Lay Roll, or output row is rewritten, backfilled, netted, or automatically reconciled by this addendum.

## Implementation boundary

This addendum is governance only. It creates no migration, code, API/UI, production behavior, or legacy endpoint removal.