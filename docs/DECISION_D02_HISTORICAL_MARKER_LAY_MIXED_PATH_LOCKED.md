# STITCHRA — D-02 HISTORICAL MARKER/LAY MIXED PATH — LOCKED

> **Decision ID:** DEC-2026-09-03-04  
> **Selected option:** A — FROZEN AS RECORDED + CONFLICT FLAGS  
> **Status:** LOCKED  
> **Decision Owner:** Reza Alpian  
> **Decision Date:** 3 September 2026

## Locked result

```text
D-02_DECISION = FROZEN_AS_RECORDED_CONFLICT_FLAGS
D-02_STATUS = LOCKED
HISTORICAL_SOURCE_ROWS = IMMUTABLE
HISTORICAL_AUTOMATIC_WINNER = PROHIBITED
HISTORICAL_MIXED_TOTAL = NOT_AUTHORIZED
SYNTHETIC_LINEAGE = PROHIBITED
CASE_SPECIFIC_CORRECTION = APPROVED_APPEND_ONLY_ONLY
```

## Classifications

| Historical evidence | Locked classification |
|---|---|
| Marker only | LEGACY_MARKER_RECORDED |
| Lay Roll only | LAY_ROLL_RECORDED |
| Marker + Lay on same MO | HISTORICAL_CONFLICT |
| Bundle without Cut Output | LEGACY_LINEAGE_INCOMPLETE |
| Missing Roll/UOM/output lineage | INSUFFICIENT_EVIDENCE |
| Shared dispatch with no source split | RECONCILIATION_REQUIRED |

## Resulting Business Rule

BR-067 is LOCKED. Historical records are preserved as recorded and classified without selecting, deduplicating, netting, or fabricating source data. Mixed and incomplete records remain readable but blocked from mutations that require an authoritative merged total.

## Explicit non-decisions

D-02 does not define a reconciliation document schema, accepted historical quantity, UOM conversion, valuation effect, accounting adjustment, or reversal workflow. These require separate approval and D-11.

## Consequences

### New execution

D-01 remains unchanged: Lay Roll is the sole Actual Fabric Consumption authority.

### Historical data

No historical row is rewritten, backfilled, deleted, deduplicated, reinterpreted, or connected through synthetic lineage.

### Implementation

No implementation is authorized. A later phase may add read-only labels and guards only after authorization.

## Dependency hand-off

```text
D-01 — LOCKED
D-03 — LOCKED
D-04 — LOCKED
D-02 — LOCKED
D-05 — NEXT / PENDING BUSINESS DECISION
```

## Change boundary

```text
Migration: NONE
Source code: NONE
API/UI: NONE
Production behavior: NONE
Historical rewrite/backfill: NONE
Legacy endpoint removal: NONE
Tests: NOT RUN — DOCUMENTATION/BUSINESS RULE REVIEW
```