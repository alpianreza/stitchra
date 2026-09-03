# STITCHRA — D-05 LEGACY PACKING SOURCE POLICY — LOCKED

> **Decision ID:** DEC-2026-09-03-05  
> **Selected option:** A — READ-ONLY UNTIL APPROVED SOURCE ATTACHMENT  
> **Status:** LOCKED  
> **Decision Owner:** Reza Alpian  
> **Decision Date:** 3 September 2026

## Locked result

```text
D-05_DECISION = READ_ONLY_UNTIL_APPROVED_SOURCE_ATTACHMENT
D-05_STATUS = LOCKED
MISSING_QC_SOURCE_READABILITY = ALLOWED
MISSING_QC_SOURCE_MUTATION = BLOCKED
AUTOMATIC_LATEST_PASS_ATTACHMENT = PROHIBITED_FOR_LEGACY
SOURCE_ATTACHMENT = EXPLICIT_APPROVED_AUDITED_ONLY
INCOMPLETE_OR_CONFLICTING_EVIDENCE = FAIL_CLOSED
AUTOMATIC_BACKFILL = PROHIBITED
```

## Resulting Business Rule

BR-068 is LOCKED. A source-less legacy Packing List remains readable but cannot mutate or create downstream FG/Shipment facts. A QC source may be attached only through an explicit, reasoned, audited, ApprovalEngine-approved action with exact tenant/MO/SO, FINAL PASS, chronology, quantity, and downstream-conflict validation.

## Explicit non-decisions

D-05 does not define the implementation schema, approval matrix thresholds, UI workflow, or remediation for a row whose evidence fails. Those remain implementation/configuration tasks after authorization.

## Consequences

### Existing technical behavior

Current silent latest-PASS attachment is evidence only and is not the locked legacy policy. It may be changed only in a later implementation phase.

### Historical data

No automatic backfill or source inference is permitted. A future approved attachment records only proven provenance; it does not rewrite transactional history.

### Impacted modules

Packing, Carton, QC, MO/SO, ITS FG receipt, Shipment, approval/audit, lineage/reporting, API/UI.

## Dependency hand-off

```text
D-01 — LOCKED
D-03 — LOCKED
D-04 — LOCKED
D-02 — LOCKED
D-05 — LOCKED
D-06 — NEXT / PENDING BUSINESS DECISION
```

## Change boundary

```text
Migration: NONE
Source code: NONE
API/UI: NONE
Production behavior: NONE
Historical backfill: NONE
Legacy endpoint removal: NONE
Tests: NOT RUN — DOCUMENTATION/BUSINESS RULE REVIEW
```