# DECISION LOG ADDENDUM — D-05 LEGACY PACKING SOURCE POLICY

> **Decision ID:** DEC-2026-09-03-05  
> **Decision:** A — READ-ONLY UNTIL APPROVED SOURCE ATTACHMENT  
> **Status:** LOCKED  
> **Decision Owner:** Reza Alpian  
> **Decision Date:** 3 September 2026  
> **Source analysis:** `docs/DECISION_D05_LEGACY_PACKING_SOURCE_POLICY.md`

## Decision

A legacy Packing List with missing `qc_inspection_id` remains readable but is read-only until an eligible QC FINAL PASS source is attached through an explicit approved action.

## Locked policy

While the QC source is missing, the Packing List must not receive:

- Carton additions or changes;
- finalize/APPROVED progression;
- ITS `PRODUCTION_RECEIPT`;
- Shipment creation;
- other downstream status progression.

A source attachment is permitted only when all controls pass:

- explicit action and reason;
- identified user;
- audit trail;
- ApprovalEngine approval;
- exact same company, Sales Order, and Production Order;
- QC stage `FINAL` and verdict `PASS`;
- chronology is plausible;
- existing Carton quantity does not exceed the selected PASS lot quantity;
- no conflicting ITS receipt, Shipment, or downstream fact;
- incomplete or conflicting evidence fails closed.

Attachment changes only the controlled provenance relationship. It does not rewrite QC, Carton, ITS, Shipment, or quantity history.

## Rationale

The nullable FK preserves historical readability, but silently choosing the latest PASS can fabricate provenance when multiple QC cycles or chronology conflicts exist. Controlled approval preserves BR-080 traceability while allowing evidence-backed recovery.

## Impacted modules

Packing List; Carton; QC Inspection/NCR cycles; MO/SO; ITS Production Receipt; Shipment; ApprovalEngine; audit; lineage/reporting; API/UI.

## Implementation consequence

This decision does not authorize implementation. A later phase must replace silent latest-PASS attachment for legacy rows with an explicit approved action and enforce read-only guards while the source is missing. No legacy endpoint is removed during review.

## Historical-data consequence

- No automatic backfill.
- No QC, Carton, ITS, Shipment, or quantity row rewrite.
- A future approved attachment may add the proven source relationship only; rejected/insufficient cases remain `MISSING_LEGACY_SOURCE` and read-only.

## Dependencies

```text
D-02 = FROZEN_AS_RECORDED_CONFLICT_FLAGS — LOCKED
D-05 = READ_ONLY_UNTIL_APPROVED_SOURCE_ATTACHMENT — LOCKED
        ↓
D-06 WIP Valuation — NEXT / PENDING
```

## Change boundary

```text
Migration: NONE
Source code: NONE
API/UI behavior: NONE
Production behavior: NONE
Historical backfill: NONE
Legacy endpoint removal: NONE
Tests: NOT RUN — DOCUMENTATION/BUSINESS RULE REVIEW
```