# STITCHRA — D-01 CLOSURE: ACTUAL FABRIC CONSUMPTION AUTHORITY

> **Status:** LOCKED  
> **Decision:** B — `LAY_ROLL`  
> **Decision ID:** DEC-2026-09-03-01  
> **Decision owner:** Reza Alpian  
> **Decision date:** 3 September 2026  
> **Analysis source:** `DECISION_D01_ACTUAL_FABRIC_CONSUMPTION.md`

## Business Rule produced

For new execution, Lay Roll is the sole Actual Fabric Consumption authority. Marker remains planning/length/efficiency and legacy evidence and cannot remain a competing operational consumption writer after implementation.

## Rationale

The selection follows PF-05 and BR-120 evidence and the existing deterministic Roll→Lay→Cut Output→Bundle lineage. The decision explicitly clarifies the conflicting Marker wording in BR-031; it is not inferred from existing implementation.

## Boundaries

- Documentation/Business Rule closure only.
- No PHP or TypeScript change.
- No migration or schema change.
- No production behavior change.
- No legacy endpoint removal.
- No historical rewrite, backfill, recalculation, or reconciliation.
- ITS inventory authority is unchanged.
- Actual Cost continues to use valued ITS issue minus return.
- Backflush remains unresolved pending D-03/D-04.

## Impacted modules

Cutting, Production, Material Issue/Return, Fabric dispatch balance, Fabric Roll, MO allocations, Cut Output, Bundle, Actual Cost boundary, Backflush boundary, and governance documentation.

## Historical consequence

All historical Marker-only, Lay-only, mixed, legacy Bundle, missing-lineage, and shared-dispatch evidence remains unchanged. D-02 will define policy only after D-03 and D-04 according to the locked dependency order.

## Next dependency

```text
D-03 — Whole-MO Production Output Authority
```

D-02 is not opened before D-03 and D-04 are closed.