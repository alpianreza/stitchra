# ERP GARMENT — BUSINESS RULES v1.4 ADDENDUM

> **Status:** LOCKED  
> **Date:** 3 September 2026  
> **Source decision:** DEC-2026-09-03-01 / D-01  
> **Decision owner:** Reza Alpian  
> **Effect:** This addendum supersedes BR-031 only where BR-031 names Marker realization as Actual Fabric Consumption authority. Other BR-031 controls remain unless explicitly changed below.

## BR-031 — Estimated vs Actual Consumption (clarified)

| Code | Status | Rule |
|---|---|---|
| BR-031 | 🔒 LOCKED / CLARIFIED | Estimated and actual consumption remain separate. For new execution, `LAY_ROLL` is the sole Actual Fabric Consumption authority. Actual Fabric quantity uses the existing Fabric Roll use-UOM and remains bounded by eligible dispatch, physical remaining, BR-053, audit, transaction locks, and `consumed + returned <= dispatched`. Marker remains marker-length/efficiency evidence and historical compatibility data; it must not be a competing Actual Fabric Consumption writer for new execution. |

### Normative controls

- Sole new-execution authority: `LAY_ROLL`.
- Authoritative lineage: `Fabric Roll → Material Issue/Dispatch → Lay Roll → Lay → Cut Output → Bundle`.
- Marker/Marker Log: planning, marker-length, efficiency, and legacy evidence; not the authoritative consumption writer for new execution.
- No second writer may mutate new-execution dispatch consumption, physical Fabric Roll remaining, or MO actual consumption.
- ITS remains inventory authority; D-01 creates no new inventory movement.
- Material Issue and Production Return continue to define the current inventory and valued material-cost boundary.
- Existing BR-053 shade, UOM, quantity, approval, locking, and audit controls remain mandatory.
- Historical Marker/Lay data is not rewritten or backfilled.
- D-02 must define historical compatibility/reconciliation.
- D-03 and D-04 must define whole-MO output and ACTUAL-versus-BACKFLUSH semantics.
- Legacy Marker endpoint removal or behavior change is not authorized by this documentation-only rule review.

Any change to this authority requires a new explicit Business Owner decision.