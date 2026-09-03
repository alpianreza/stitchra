# DECISION LOG ADDENDUM — D-11 REVERSAL AND TIMING POLICY

> **Decision ID:** DEC-2026-09-03-11  
> **Decision:** A — OPEN-PERIOD REVERSAL/REPOST; CLOSED-PERIOD PROSPECTIVE ADJUSTMENT  
> **Status:** LOCKED  
> **Decision Owner:** Reza Alpian  
> **Decision Date:** 3 September 2026  
> **Source analysis:** `docs/DECISION_D11_REVERSAL_AND_TIMING_POLICY.md`

## Decision

Corrections use append-only reversal/repost in the original period while that period is OPEN. If the original period is CLOSED, it is not reopened and the original journal is not voided or mutated; a separate approved prior-period adjustment or late-variance entry is posted in the current OPEN period.

## Locked policy

### Original period OPEN

- Use an explicit reversal and corrected repost in the original OPEN period.
- Preserve source document, original date/period, reason, identified user, approval, audit trail, and deterministic correction key.
- Do not edit or delete original journal lines.

### Original period CLOSED

- Do not reopen the period by default.
- Do not mark the original closed-period journal VOID merely because a later-period adjustment is posted.
- Post a separate approved prior-period adjustment/late-variance entry in the current OPEN period.
- Preserve original source date/period, discovery/finalization date, posting date/period, reason, identified user, approval, mapping, and audit references.

### Operational precedence

When quantity or stock changes, an authorized operational return, cancellation, or adjustment document and its ITS movement must exist before the accounting correction.

### Fail-closed controls

Missing source lineage, reason, user, approval, allocation, account mapping, OPEN target period, or deterministic correction key blocks posting.

No materiality threshold, retained-earnings account, or statutory restatement rule is inferred. Those require separately configured or approved Finance authority.

## Rationale

This preserves BR-103 closed-period integrity while allowing D-07 through D-10 provisional-to-actual variance and operational corrections to be represented without rewriting history.

## Impacted modules

Journal/GL; Period Close; ITS adjustments/returns; Material Issue/Return; WIP/FG valuation; Shipment return/cancellation; Actual Cost variance; COGS; account mapping; approvals; audit; reporting.

## Implementation consequence

This decision does not authorize implementation. A later phase must define operational reversal documents, prior-period/variance events, immutable closed-journal handling, correction version keys, account mappings, approval/audit, allocation, period validation, and tests.

Existing `reverseIntoPeriod()` behavior that marks the original journal VOID is technical evidence only and must not be used as the closed-period policy until corrected under an approved implementation task.

## Historical-data consequence

- No existing transaction or journal is automatically voided, reversed, reposted, reclassified, or backfilled.
- No CLOSED period is reopened.
- Policy applies prospectively after approved implementation cutover.

## Dependency closure

```text
D-01 → D-03 → D-04 → D-02 → D-05 → D-06 → D-07 → D-09 → D-08 → D-10 → D-11
ALL LOCKED
```

## Change boundary

```text
Migration: NONE
Source code: NONE
Journal/reversal posting: NONE
Production behavior: NONE
Historical backfill: NONE
Tests: NOT RUN — DOCUMENTATION/BUSINESS RULE REVIEW
```