# DECISION LOG ADDENDUM — D-02 HISTORICAL MARKER/LAY MIXED PATH POLICY

> **Decision ID:** DEC-2026-09-03-04  
> **Decision:** A — FROZEN AS RECORDED + CONFLICT FLAGS  
> **Status:** LOCKED  
> **Decision Owner:** Reza Alpian  
> **Decision Date:** 3 September 2026  
> **Source analysis:** `docs/DECISION_D02_HISTORICAL_MARKER_LAY_MIXED_PATH.md`

## Decision

Historical cutting-consumption evidence is preserved exactly as recorded. Stitchra must not select a historical winner, deduplicate quantities, or synthesize missing lineage automatically.

## Locked classifications

```text
Marker-only history          = LEGACY_MARKER_RECORDED
Lay-only history             = LAY_ROLL_RECORDED
Marker + Lay on same MO      = HISTORICAL_CONFLICT
Legacy Bundle no Cut Output  = LEGACY_LINEAGE_INCOMPLETE
Missing Roll/UOM/output      = INSUFFICIENT_EVIDENCE
Shared dispatch attribution  = RECONCILIATION_REQUIRED
```

## Operational policy

- Historical source rows remain readable and immutable.
- A historical mixed MO has no automatic authoritative consumption total.
- Mutation, completion, or recalculation that would choose or merge historical sources remains blocked.
- Shared dispatch totals cannot be used to infer Marker-versus-Lay attribution.
- No synthetic Lay, Lay Roll, Cut Output, Bundle link, Roll, UOM, or output lineage is created.
- New execution continues to follow D-01: Lay Roll is the sole Actual Fabric Consumption authority.
- If a historical correction is later required, it must use a separately approved, case-specific append-only reconciliation/adjustment under BR-013/017 and D-11.

## Rationale

Repository structure can identify source families but cannot prove whether historical mixed quantities are duplicates, distinct physical uses, or overlapping representations. Freezing the evidence and flagging conflict preserves auditability without inventing a quantity.

## Impacted modules

Cutting/Marker; Lay/Lay Roll; Fabric Roll; dispatch balance; MO material allocations; Cut Output; Bundle; Shop Floor lineage; Actual Cost history; inventory; audit/approval; reporting/governance.

## Implementation consequence

This decision does not authorize implementation. A later phase may add read-only classification and guards. Any authoritative historical correction requires a separate approved reconciliation design; source rows remain immutable.

## Historical-data consequence

No rewrite, backfill, deduplication, source selection, quantity netting, UOM inference, or synthetic lineage creation is permitted.

## Dependencies

```text
D-01 = LAY_ROLL — LOCKED
D-03 = SEPARATE_NAMED_MEASURES — LOCKED
D-04 = EXCLUSIVE_PER_MATERIAL_REQUIRED_NAMED_STAGE — LOCKED
D-02 = FROZEN_AS_RECORDED_CONFLICT_FLAGS — LOCKED
        ↓
D-05 Legacy Packing Source — NEXT / PENDING
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