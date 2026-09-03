# ERP GARMENT BUSINESS RULES — v1.14 ADDENDUM

> **Status:** LOCKED  
> **Effective decision:** DEC-2026-09-03-11  
> **Decision Owner:** Reza Alpian  
> **Decision Date:** 3 September 2026  
> **Scope:** D-11 Reversal and Timing Policy

## BR-109 — Open-Period Correction and Closed-Period Prospective Adjustment

**Status:** LOCKED

### Open original period

Corrections use an explicit append-only reversal and corrected repost in the original OPEN period. Source document, original date/period, reason, identified user, approval, audit, and deterministic correction key are mandatory.

### Closed original period

A CLOSED period is not reopened by default. The original closed-period journal remains unchanged and must not be marked VOID merely because a later-period adjustment is posted. A separate approved prior-period adjustment or late-variance entry is posted in the current OPEN period with original and current timing evidence preserved.

### Operational precedence

If stock or quantity changes, an authorized operational return/cancellation/adjustment and ITS movement must precede the accounting correction.

### Fail-closed

Missing lineage, reason, user, approval, allocation, mapping, OPEN target period, or deterministic correction key blocks posting.

No materiality threshold, retained-earnings mapping, or statutory restatement treatment is inferred; those require separate Finance authority.

## Clarifications

- BR-013/017 preserve append-only correction evidence.
- BR-103 preserves CLOSED-period integrity.
- BR-105 through BR-108 use this rule for late actual variance, FG/Shipment adjustments, and COGS correction timing.
- Existing `reverseIntoPeriod()` behavior is not closed-period authority where it marks an original closed-period journal VOID.

## Historical boundary

No historical transaction, ITS movement, valuation, journal, status, or period is automatically changed, voided, reposted, reclassified, or backfilled.

## Implementation boundary

This addendum is governance only. It creates no migration, code, journal, reversal, API/UI change, period reopening, or production behavior change.