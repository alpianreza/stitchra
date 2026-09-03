# ERP GARMENT BUSINESS RULES — v1.7 ADDENDUM

> **Status:** LOCKED  
> **Effective decision:** DEC-2026-09-03-04  
> **Decision Owner:** Reza Alpian  
> **Decision Date:** 3 September 2026  
> **Scope:** D-02 Historical Marker/Lay Mixed Path Policy

## BR-067 — Frozen Historical Cutting Evidence and Conflict Classification

**Status:** LOCKED

Historical Marker, Lay Roll, dispatch, Cut Output, Bundle, and MO-allocation evidence is preserved as recorded.

Locked classifications:

- Marker-only: `LEGACY_MARKER_RECORDED`;
- Lay-only: `LAY_ROLL_RECORDED`;
- Marker and Lay on the same MO: `HISTORICAL_CONFLICT`;
- Bundle without Cut Output: `LEGACY_LINEAGE_INCOMPLETE`;
- missing Roll/UOM/output lineage: `INSUFFICIENT_EVIDENCE`;
- shared dispatch without source attribution: `RECONCILIATION_REQUIRED`.

No historical winner or authoritative mixed total may be inferred automatically. Mixed or insufficient-evidence records remain readable, but mutation/recompletion/recalculation that would select or merge sources is blocked.

Any later correction must use an approved, case-specific, append-only reconciliation/adjustment under BR-013/017 and the locked D-11 policy when available. Source rows must not be edited.

D-01 remains authoritative for new execution: `LAY_ROLL` only.

## Historical boundary

No rewrite, backfill, deduplication, source selection, quantity netting, UOM inference, or synthetic Lay/Cut Output/Bundle linkage is permitted.

## Implementation boundary

This addendum is governance only. It creates no migration, code, API/UI, production behavior, or legacy endpoint removal.