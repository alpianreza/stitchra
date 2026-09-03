# DECISION LOG ADDENDUM — D-01 ACTUAL FABRIC CONSUMPTION AUTHORITY

> **Decision:** DEC-2026-09-03-01  
> **Date:** 3 September 2026  
> **Decision owner:** Reza Alpian  
> **Selected option:** B — LAY ROLL  
> **Status:** LOCKED  
> **Supersedes:** the pending status in `DECISION_D01_ACTUAL_FABRIC_CONSUMPTION.md`, the open Marker/Lay authority note in `DECISION_LOG_OPEN_2026-09-02_CUTTING_CONSUMPTION_AUTHORITY.md`, and BR-031 only where it names Marker realization as Actual Fabric Consumption authority.

## D-01 — Actual Fabric Consumption Authority

For **new execution**, `LAY_ROLL` is the sole official authority for Actual Fabric Consumption.

### Locked rule

1. **Authority transaction** — Actual Fabric Consumption is the quantity persisted by Lay Roll in the existing Fabric Roll use-UOM.
2. **Authoritative writer** — for new execution, only the Lay Roll path may authoritatively mutate operational fabric consumption, including dispatch consumed quantity, physical Fabric Roll remaining, and MO actual-consumption synchronization.
3. **Marker role** — Marker and Marker Log remain planning/marker-length/efficiency evidence and legacy compatibility records. They are not an Actual Fabric Consumption writer for new execution.
4. **No competing writer** — Marker must not remain a competing writer of dispatch consumption, physical Roll remaining, or MO actual consumption for new execution after this decision is implemented.
5. **Lineage** — the authoritative new-execution chain is `Fabric Roll → Material Issue/Dispatch → Lay Roll → Lay → Cut Output → Bundle`.
6. **UOM and quantity controls** — existing Fabric Roll use-UOM, eligible dispatch, physical remaining, BR-053 shade controls, transaction locks, audit, and `consumed + returned <= dispatched` remain mandatory.
7. **Inventory boundary** — D-01 creates no stock-ledger movement. ITS remains the inventory authority; Material Issue and Production Return remain the inventory movements.
8. **Actual Cost boundary** — this decision establishes operational consumption quantity authority only. Existing Actual Cost continues to read valued ITS Material Issue minus Production Return until later costing/valuation decisions say otherwise.
9. **Backflush boundary** — D-01 does not define whole-MO production output or ACTUAL-versus-BACKFLUSH semantics. D-03 and D-04 remain required.
10. **Historical protection** — no historical Marker row, Lay Roll row, dispatch balance, Fabric Roll quantity, MO allocation, Bundle, Cut Output, ITS ledger, or cost is rewritten, backfilled, deleted, or reinterpreted by this decision.
11. **Cutover/historical policy** — classification and treatment of Marker-only, Lay-only, mixed, missing-lineage, and shared-dispatch historical data belong to D-02. This decision does not select a reconciliation policy.
12. **Legacy endpoint** — this documentation decision does not remove or alter the legacy Marker endpoint. Any behavior change belongs to a separate implementation phase after dependent decisions are locked.

## BR-031 clarification

BR-031 is amended only for Fabric Actual Consumption authority:

- Estimated and actual consumption remain separate.
- For new execution, actual Fabric consumption comes from `LAY_ROLL`, not Marker realization.
- Marker remains marker-length/efficiency and legacy evidence.
- Actual material cost remains based on the authoritative inventory/cost boundary already defined for ITS issue and return; D-01 does not create a new cost ledger or wastage valuation rule.

## Rationale

- PF-05 records Roll usage and leftover from Lay usage while assigning Marker the length/efficiency role.
- BR-120 explicitly uses `lay_rolls` in the end-to-end traceability chain.
- Existing Lay Roll data provides the strongest persisted lineage from Roll and dispatch through Lay, Cut Output, and Bundle.
- Lay Roll already enforces use-UOM, dispatch eligibility, physical remaining, BR-053, locks, and audit.
- The choice is based on authority and traceability evidence, not merely on the Lay path being newer.

## Impacted modules

- Cutting: Lay, Lay Roll, Marker, Cut Order, Cut Output, Bundle.
- Production: Material Issue, Fabric dispatch balance, MO material allocations, Backflush boundary.
- Receiving/Inventory: Fabric Roll operational remaining and ITS boundary.
- Shop Floor/QC/Packing: downstream Bundle lineage; no immediate behavior change in this review phase.
- Costing/Finance: actual-material-cost source boundary remains ITS issue minus return.
- Governance: BR-031 clarification; D-02/D-03/D-04 dependencies.

## Implementation consequence

Implementation is **not part of this decision commit**. A later approved implementation must prevent Marker from being a competing new-execution consumption writer, preserve Marker legacy reads, keep Lay Roll as the sole new-execution writer, preserve transaction/locking/UOM/audit controls, and update authority-facing documentation/tests/UI without inventing historical reconciliation.

## Historical-data consequence

Historical Marker data is preserved unchanged. Historical path classification, compatibility, reconciliation, correction, and cutover rules remain blocked pending D-02. No migration, backfill, quantity rewrite, or historical recalculation is authorized.

## Dependencies

```text
D-01 LOCKED: LAY_ROLL
        ↓
D-03 Whole-MO Production Output
        ↓
D-04 ACTUAL vs BACKFLUSH
        ↓
D-02 Historical Marker/Lay Mixed Path Policy
```

Changes to this decision require a new explicit Business Owner decision and a new governance entry.