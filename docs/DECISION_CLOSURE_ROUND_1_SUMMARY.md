# STITCHRA — DECISION CLOSURE ROUND 1 — SUMMARY

> **Status:** COMPLETE — ALL D-01 THROUGH D-11 LOCKED  
> **Completion date:** 3 September 2026  
> **Decision Owner:** Reza Alpian  
> **Repository:** `alpianreza/stitchra` / `main`  
> **Mode:** Governance/documentation only

## Locked dependency sequence

```text
D-01 → D-03 → D-04 → D-02 → D-05 → D-06 → D-07 → D-09 → D-08 → D-10 → D-11
```

| Decision | Decision ID | Locked selection | Resulting rule |
|---|---|---|---|
| D-01 Actual Fabric Consumption | DEC-2026-09-03-01 | Lay Roll | BR-031 clarified |
| D-03 Whole-MO Production Output | DEC-2026-09-03-02 | Separate Named Measures | BR-065 |
| D-04 Actual vs Backflush | DEC-2026-09-03-03 | Exclusive per Material + Named Stage | BR-066 |
| D-02 Historical Marker/Lay | DEC-2026-09-03-04 | Frozen as Recorded + Conflict Flags | BR-067 |
| D-05 Legacy Packing Source | DEC-2026-09-03-05 | Read-only until Approved Attachment | BR-068 |
| D-06 WIP Valuation | DEC-2026-09-03-06 | Provisional Standard + Actual Variance | BR-069 |
| D-07 FG Valuation | DEC-2026-09-03-07 | Provisional Standard + Actual Variance | BR-105 |
| D-09 Cost per PCS | DEC-2026-09-03-08 | FG Received Primary + Named KPIs | BR-106 |
| D-08 Shipment Valuation | DEC-2026-09-03-09 | Prevailing FG Moving Average | BR-107 |
| D-10 COGS | DEC-2026-09-03-10 | Shipment-date + Later Actual Variance | BR-108 |
| D-11 Reversal/Timing | DEC-2026-09-03-11 | Open Repost; Closed Adjust | BR-109 |

## Authority chain produced

```text
New fabric actual                 = Lay Roll
Production quantities             = separate named measures
Material consumption method       = ACTUAL or BACKFLUSH, exclusive per MO material
Historical Marker/Lay evidence    = frozen as recorded; conflicts flagged
Legacy Packing missing QC source  = read-only until approved attachment
Open WIP value                    = provisional MO standard + later actual variance
FG value                          = provisional standard + later actual variance
Official actual cost per FG PCS   = actual MO cost / company-owned FG received qty
Shipment inventory cost           = prevailing FG Moving Average
Base COGS                         = valued ITS Shipment cost on ship date
Open-period correction            = reversal + corrected repost in original period
Closed-period correction          = separate approved adjustment in current OPEN period
```

## Implementation boundary

These decisions are official inputs for a future implementation phase. They do not themselves authorize code, migration, valuation posting, backfill, source attachment, period reopening, or historical rewrite.

Implementation planning must preserve:

- tenant/company isolation;
- ITS and journal append-only controls;
- named quantity authorities;
- immutable MO standard snapshots;
- ApprovalEngine, identified user, reason, and audit where required;
- deterministic idempotency;
- fail-closed behavior on missing authority;
- no automatic historical reinterpretation.

## Historical boundary

```text
Historical Marker/Lay rewrite: PROHIBITED
Historical Packing auto-attachment: PROHIBITED
Historical WIP/FG valuation backfill: PROHIBITED
Historical Shipment revaluation/COGS auto-post: PROHIBITED
Historical journal auto-void/repost/reclassification: PROHIBITED
Closed-period reopening: PROHIBITED BY DEFAULT
```

## Remaining implementation dependencies

Business authority is now locked, but implementation remains blocked until an approved plan defines, at minimum:

- stage-allocation profiles and named WIP quantity sources;
- valuation-layer and variance document design;
- MO close/freeze implementation;
- grade/scrap/rework cost allocation;
- operational returns/cancellations and ITS reversals;
- prior-period/late-variance events and account mappings;
- period, approval, audit, concurrency, idempotency, and reconciliation tests;
- safe prospective cutover without historical backfill.

## Verification status

```text
Documentation commit verification: REQUIRED
Migration: NONE
Source code: NONE
Runtime tests: NOT RUN — DOCUMENTATION/BUSINESS RULE REVIEW
Production readiness: NO-GO remains unchanged
```