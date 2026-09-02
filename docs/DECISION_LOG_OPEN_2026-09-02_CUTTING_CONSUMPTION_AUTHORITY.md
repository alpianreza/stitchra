# DECISION LOG — OPEN CUTTING CONSUMPTION AUTHORITY

> **Topic:** Legacy Marker vs Lay Consumption Authority
> **Date:** 2 September 2026
> **Status:** ⚪ NOT DEFINED / BLOCKED
> **Scope of block:** convergence of actual fabric-consumption authority only; the defined Lay → Cut Output → Bundle flow may continue.

## Evidence from locked requirements

The official requirements do not identify one unambiguous authority when the legacy Marker path and the new Lay Roll path coexist:

- BR-031 / BR-014 says actual consumption comes from marker realization and is netted with leftover return for actual costing.
- BR-041 says actual fabric issue is per roll from the lay.
- PF-05 records rolls used per Lay and calculates leftover from Lay usage, while Marker records marker length and efficiency.
- OBD-006 / BR-053 preserves existing reservation, issue, dispatch, consumption, physical remaining, and leftover controls, but does not choose between Marker Log and Lay Roll as the single actual-consumption authority.

## Current implementation conflict

- `CuttingService::recordMarker()` consumes `fabric_dispatch_balances` and physical Fabric Roll remaining.
- `LayExecutionService::addRollInternal()` consumes the same controls.
- `CuttingService::complete()` adds Marker Log usage to `mo_material_allocations`.
- `LayExecutionService::completeLay()` sets `mo_material_allocations` from Lay Roll totals.

Shared dispatch/physical controls limit direct over-consumption, but mixed execution can still produce order-dependent double-counting or overwrite/undercount in MO actual consumption.

## Binding guardrail while open

1. Do not delete the legacy Marker endpoint.
2. Do not backfill or rewrite historical Marker Log, Bundle, Lay, or consumption data by assumption.
3. Do not declare Marker or Lay Roll the sole consumption authority until a business decision is locked.
4. Preserve `consumed + returned <= dispatched`, Fabric Roll physical remaining, existing use-UOM, tenant scope, and audit controls.
5. Continue only changes independent of the authority decision: Lay lifecycle enforcement, Cut Output ceilings, Bundle-from-Cut-Output lineage, audit, authorization, and UI execution controls.

## Decision required

The owner must later lock:

- whether Marker is planning/efficiency data only or also an actual-consumption transaction;
- whether Lay Roll is the sole actual fabric-consumption source for new Cutting Orders;
- whether one Cut Order may use both paths;
- precedence/reconciliation when historical Marker and Lay Roll records coexist;
- historical migration/backfill policy;
- idempotency and reversal behavior for completion and actual-cost synchronization.

Until resolved, legacy convergence remains **P1 OPEN / BLOCKED**, not SAFE.
