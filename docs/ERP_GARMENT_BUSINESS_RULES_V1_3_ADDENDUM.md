# ERP GARMENT — BUSINESS RULES v1.3 ADDENDUM

> **Status:** LOCKED
> **Date:** 2 September 2026
> **Source decision:** DEC-2026-09-02-01 / OBD-006
> **Effect:** This addendum supersedes the BR-053 row in `ERP_GARMENT_BUSINESS_RULES.md` v1.2.

## BR-053 — Shade Compatibility & Lay Rule

| Code | Status | Rule |
|---|---|---|
| BR-053 | 🔒 LOCKED / IMPLEMENTATION READY | Shade validation is configurable per buyer and defaults ENABLED when no buyer configuration exists. When enabled, each Lay accepts only non-NULL Fabric Rolls with the exact same `shade_group_id`. A mismatch can only be applied after an explicit, reasoned, audited ApprovalEngine request is APPROVED. Lay Roll uses the Fabric Roll use-UOM, cannot exceed eligible dispatched/physical quantity, creates no new inventory movement, and must preserve `Fabric Roll → Lay Roll → Lay → Cut Output → Bundle` traceability. |

### Normative controls

- Missing buyer configuration: enabled, fail-closed.
- `shade_group_id=NULL`: incompatible when enabled.
- Exact equality only; no numeric tolerance or implicit mapping.
- Disabled buyer rule: no BR-053 shade blocking.
- Override requires ApprovalEngine; ordinary permissions never bypass validation.
- Quantity remains governed by `consumed + returned <= dispatched` and physical roll remaining.
- New bundles must reference Cut Output; Cut Output must reference Lay.
- BR-053 introduces no stock-ledger movement.
