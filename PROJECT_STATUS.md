# Stitchra — Project Status

> Updated: 31 August 2026

## Current state

Phase 1–7 hardening is implemented in code with regression-test evidence. Production readiness is **not approved** because deterministic lockfiles, clean CI, real concurrency tests, and UAT are still absent.

## Implemented evidence

- Phase 1–3: core concurrency/tenant/audit/permissions, Master Data validation, Sales/BOM/Routing/Cost versioning.
- Phase 4: ITS locking/idempotency, exact stock dimensions, receiving/QC, valuation, and 3-way match.
- Phase 5: strict MRP selection/conversion, unique MO creation, dimensional reservations, exact issue, delta backflush.
- Phase 6: cut quantity ceilings, issued-roll marker consumption, immutable BOM snapshot, serialized bundle scans, finishing gate, and rework lifecycle.
- Phase 7: serialized QC cycles, tenant-safe defect evidence, cumulative packing, one shipment per PL, per-matrix tolerance/closure, and safe partial subcon returns.

## Known gaps

- BR-042 explicit roll warehouse/dispatched/consumed/returned state model.
- Offline scan replay key/conflict handling and separate device authentication.
- Formal AQL table validation and QC evidence attachment controls.
- Real lockfiles and clean runtime/CI verification.

## Phase status

| Phase | Status |
|---|---|
| 1–3 | Implementation hardening present; CI pending |
| 4 | Implementation hardening present; CI/concurrency pending |
| 5 | Implementation hardening present; BR-042/CI/concurrency pending |
| 6 | Implementation hardening present; offline/device/CI pending |
| 7 | Implementation hardening present; AQL sign-off/CI/concurrency pending |
| 8 | Finance/actual costing/BEP broadly implemented; dedicated audit required |
| 9 | Reporting/dashboard/hardening in progress |

## Immediate blockers

1. Generate `apps/api/composer.lock` and `apps/web/package-lock.json` externally and commit them.
2. Run clean PHP/web CI and retain evidence.
3. Add real multi-process concurrency tests for critical counters and stock/document transitions.
4. Resolve BR-042 roll state, offline replay, and browser/device auth design.
5. Obtain formal AQL configuration validation and UAT approval.
