# Stitchra — Project Status

> Updated: 31 August 2026

## Current state

Phase 1–8 implementation hardening is present on `main`. Production readiness is **not approved**: deterministic lockfiles, clean CI, migration smoke tests, real concurrency tests, accounting validation, and UAT are still outstanding.

## Evidence by stage

- 1–3: core concurrency/tenant/audit/permissions, master validation, Sales/BOM/Routing/Cost versioning.
- 4: ITS locking/idempotency, exact inventory dimensions, receiving/QC, valuation, and 3-way match.
- 5: strict MRP selection/conversion, unique MO, dimensional reservations, exact issue, delta backflush.
- 6: cutting ceilings, issued-roll marker consumption, immutable BOM snapshot, serialized scans, finishing/rework gates.
- 7: serialized QC cycles, cumulative packing, one shipment per PL, matrix tolerance/closure, safe partial subcon returns.
- 8: composite GL periods, balanced/idempotent journals, unique reversals, locked AR/AP, historical actual costing, and latest-style BEP.

## Known gaps

- BR-042 roll warehouse/dispatched/consumed/returned state model.
- Offline scan replay key and separate shop-floor device authentication.
- Formal AQL validation and attachment controls.
- MO standard-cost-sheet snapshot.
- Tax/withholding, FX revaluation, bank reconciliation, and accounting period-close sign-off.
- Real lockfiles and clean CI/runtime evidence.

## Phase status

| Phase | Status |
|---|---|
| 1–3 | Implementation hardening present; CI pending |
| 4 | Implementation hardening present; CI/concurrency pending |
| 5 | Implementation hardening present; BR-042/CI/concurrency pending |
| 6 | Implementation hardening present; offline/device/CI pending |
| 7 | Implementation hardening present; AQL/CI/concurrency pending |
| 8 | Implementation hardening present; accounting/CI/concurrency pending |
| 9 | Reporting/dashboard/operational hardening requires dedicated audit |

## Immediate blockers

1. Generate and commit `apps/api/composer.lock` and `apps/web/package-lock.json` externally.
2. Run clean migrations plus PHP/web CI and retain evidence.
3. Add multi-process tests for critical counters, stock, planning, shop-floor, outbound, and finance transitions.
4. Resolve BR-042, offline replay, device auth, and cost-sheet snapshot design.
5. Obtain AQL, accounting, UAT, and pilot approval.
