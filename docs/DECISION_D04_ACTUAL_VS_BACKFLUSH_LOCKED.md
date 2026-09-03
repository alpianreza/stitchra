# STITCHRA — D-04 ACTUAL VS BACKFLUSH — LOCKED

> **Decision ID:** DEC-2026-09-03-03  
> **Selected option:** A — EXCLUSIVE PER MATERIAL + REQUIRED NAMED STAGE  
> **Status:** LOCKED  
> **Decision Owner:** Reza Alpian  
> **Decision Date:** 3 September 2026

## Locked result

```text
D-04_DECISION = EXCLUSIVE_PER_MATERIAL_REQUIRED_NAMED_STAGE
D-04_STATUS = LOCKED
ACTUAL_BACKFLUSH_OVERLAP_PER_MO_MATERIAL = PROHIBITED
FABRIC_BACKFLUSH = PROHIBITED
FABRIC_PHYSICAL_CONSUMPTION = LAY_ROLL
INVENTORY_MOVEMENT_AUTHORITY = ITS
BACKFLUSH_GENERIC_QTY_PRODUCED = PROHIBITED
BACKFLUSH_NAMED_STAGE = REQUIRED
MISSING_METHOD_SOURCE_UOM = FAIL_CLOSED
```

## Resulting Business Rule

BR-066 is LOCKED:

- one MO/material uses exactly one consumption method;
- fabric uses ACTUAL Material Issue for inventory dispatch and Lay Roll for physical consumption;
- eligible non-fabric materials may Backflush only under a configured/snapshotted method;
- Backflush must name an authoritative D-03 stage measure;
- Backflush posts cumulative delta from locked BOM basis and prior Backflush postings;
- incomplete source/UOM evidence fails closed;
- corrections are append-only and approval-controlled.

## Explicit non-decisions

D-04 does not select:

- the actual material-class mapping;
- the named stage for any specific material class;
- canonical UOM conversion values;
- correction/reversal document design;
- historical ACTUAL/BACKFLUSH reconciliation;
- implementation cutover timing.

Those require controlled configuration/design or downstream decisions. No assumption is added here.

## Consequences

### Impacted modules

BOM/material; MO allocation/reservation; Material Issue; Lay Roll/dispatch; ITS; named output measures; Packing/FG; Actual Cost; approval/audit; reporting.

### Implementation

No implementation is authorized. The legacy Backflush endpoint remains present. Later implementation must add guards and explicit source/configuration only after authorization.

### Historical data

No historical ACTUAL, BACKFLUSH, ledger, reservation, Lay Roll, or output data is rewritten, backfilled, netted, or automatically reconciled.

## Dependency hand-off

```text
D-01 = LAY_ROLL — LOCKED
D-03 = SEPARATE_NAMED_MEASURES — LOCKED
D-04 = EXCLUSIVE_PER_MATERIAL_REQUIRED_NAMED_STAGE — LOCKED
D-02 = NEXT / PENDING BUSINESS DECISION
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