# DECISION LOG ADDENDUM — OBD-006 / BR-053

> **Decision:** DEC-2026-09-02-01
> **Date:** 2 September 2026
> **Status:** LOCKED
> **Supersedes:** the open OBD-006 note in `DECISION_LOG.md` and the DEFAULT status of BR-053 in Business Rules v1.2.

## OBD-006 — Shade Compatibility & Lay Rule

BR-053 is IMPLEMENTATION READY.

1. **Activation** — configurable per buyer. `enabled=true` requires validation; `enabled=false` disables BR-053 shade blocking. It must not be global-only.
2. **Default** — missing buyer configuration means ENABLED and fail-closed.
3. **NULL** — when enabled, `shade_group_id=NULL` is incompatible with every shade and cannot be allocated to a Lay.
4. **Compatibility key** — exact `shade_group_id` only. Same ID is compatible; different ID or NULL is incompatible. BR-053 adds no lot, width, material, supplier, or colorway compatibility key.
5. **Mixing** — an enabled Lay cannot mix different shade groups.
6. **Override** — mismatch bypass requires an explicit request, mandatory reason, identified user, audit trail, and ApprovalEngine. Ordinary permission is not a direct bypass.
7. **Approval** — APPROVED allows the requested Lay Roll; PENDING, REJECTED, or REVISION remains blocked. No silent/manual bypass.
8. **Tolerance** — exact match only; no numeric tolerance. Buyer-specific mapping or tolerance requires a separate business decision.
9. **Quantity** — Lay Roll cannot exceed eligible Fabric Roll quantity and remains subject to `consumed + returned <= dispatched`. No new `marker length × plies` invariant.
10. **UOM** — use the existing Fabric Roll use-UOM; no Lay-specific UOM.
11. **Lay → Cut Output** — every Cut Output must reference a Lay.
12. **Cut Output → Bundle** — new Bundles must derive from Cut Output, preserving `Fabric Roll → Lay Roll → Lay → Cut Output → Bundle` traceability.
13. **Inventory** — BR-053 creates no stock movement. Existing reservation, material issue, dispatch, consumption, physical roll remaining, and leftover-return controls remain authoritative.

Changes to this decision require a new approved OBD/DEC entry.
