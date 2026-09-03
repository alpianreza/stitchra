# ERP GARMENT BUSINESS RULES — v1.8 ADDENDUM

> **Status:** LOCKED  
> **Effective decision:** DEC-2026-09-03-05  
> **Decision Owner:** Reza Alpian  
> **Decision Date:** 3 September 2026  
> **Scope:** D-05 Legacy Packing Source Policy

## BR-068 — Controlled Legacy Packing Source Attachment

**Status:** LOCKED

A Packing List with missing `qc_inspection_id` is readable but read-only.

No Carton mutation, finalization, FG `PRODUCTION_RECEIPT`, Shipment creation, or downstream status progression is permitted until a QC source is attached through an explicit approved action.

Source attachment requires:

- reason, identified user, audit, and ApprovalEngine approval;
- exact same company, Sales Order, and Production Order;
- QC FINAL PASS;
- plausible chronology;
- quantity compatibility with existing Cartons;
- no conflict with existing ITS receipt, Shipment, or downstream facts;
- fail-closed behavior on incomplete evidence.

The attachment records provenance only. It must not rewrite QC, Carton, ITS, Shipment, or quantity history. Automatic latest-PASS inference and automatic backfill are prohibited for legacy source-less rows.

## Clarification to BR-080

BR-080 remains the Packing eligibility authority. For legacy rows, eligibility must be proven through the approved source-attachment control before any new mutation.

## Historical boundary

Existing source-less rows remain `MISSING_LEGACY_SOURCE` and readable. No automatic historical backfill or source inference is allowed.

## Implementation boundary

This addendum is governance only. It creates no migration, code, API/UI change, production behavior change, data attachment, or legacy endpoint removal.