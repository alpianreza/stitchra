# DECISION LOG ADDENDUM — D-04 ACTUAL VS BACKFLUSH SEMANTICS

> **Decision ID:** DEC-2026-09-03-03  
> **Decision:** A — EXCLUSIVE PER MATERIAL + REQUIRED NAMED STAGE  
> **Status:** LOCKED  
> **Decision Owner:** Reza Alpian  
> **Decision Date:** 3 September 2026  
> **Source analysis:** `docs/DECISION_D04_ACTUAL_VS_BACKFLUSH.md`

## Decision

For one MO/material, `ACTUAL` and `BACKFLUSH` are mutually exclusive consumption methods.

### Fabric

Roll-tracked fabric follows:

```text
Inventory issue/dispatch = ACTUAL Material Issue through ITS
Physical actual consumption = LAY_ROLL under D-01
BACKFLUSH = PROHIBITED
```

An ACTUAL Material Issue is inventory dispatch/issue evidence. It does not replace Lay Roll as the physical-consumption authority.

### Eligible non-fabric material

- Exactly one method is selected for each MO/material: `ACTUAL` or `BACKFLUSH`.
- Backflush eligibility follows the configurable material-class policy allowed by BR-041 and must be snapshotted for the MO/material before execution.
- A Backflush method must explicitly name the authoritative stage measure selected from D-03's named measures.
- Missing method, named-stage source, authoritative quantity, or canonical UOM/conversion fails closed.

### Cumulative Backflush

```text
cumulative target = locked BOM consumption basis × authoritative named-stage quantity
posting delta      = cumulative target − prior BACKFLUSH postings
```

Because ACTUAL and BACKFLUSH are exclusive, ACTUAL quantity is not silently netted against Backflush. Any correction/reversal must use an approved append-only mechanism under BR-013/017 and the later reversal decision.

## Rationale

- Preserves BR-041's hybrid capability without allowing double issue.
- Obeys D-01: fabric physical consumption belongs to Lay Roll.
- Obeys D-03: Backflush must identify a named production measure and cannot use generic `qty_produced`.
- Avoids imposing one global stage on every material class without business evidence.
- Preserves ITS as the sole inventory movement authority.

## Impacted modules

BOM/material classification; MO release/allocation; reservation; Material Issue; Lay Roll/dispatch; ITS; Shop Floor named output; Packing/FG receipt; Actual Cost; audit/approval; reporting.

## Implementation consequence

This decision does not authorize implementation. A later implementation phase must define and enforce:

- material-class method configuration and MO/material snapshot;
- one-mode exclusivity;
- named-stage linkage;
- canonical UOM conversion;
- cumulative delta and deterministic idempotency;
- approved reversal/adjustment;
- fail-closed execution when evidence is incomplete.

The legacy Backflush endpoint is not removed in this phase.

## Historical-data consequence

- Existing ACTUAL, BACKFLUSH, and overlapping rows remain unchanged.
- No historical quantity is rewritten, netted, backfilled, or reclassified as authoritative by mutation.
- Historical overlap becomes evidence for D-02 classification and controlled handling.

## Dependencies

```text
D-01 = LAY_ROLL — LOCKED
D-03 = SEPARATE_NAMED_MEASURES — LOCKED
D-04 = EXCLUSIVE_PER_MATERIAL_REQUIRED_NAMED_STAGE — LOCKED
        ↓
D-02 Historical Marker/Lay Mixed Path Policy — NEXT / PENDING
```

## Change boundary

```text
Migration: NONE
Source code: NONE
API/UI behavior: NONE
Production behavior: NONE
Historical rewrite/backfill: NONE
Legacy endpoint removal: NONE
Tests: NOT RUN — DOCUMENTATION/BUSINESS RULE REVIEW
```