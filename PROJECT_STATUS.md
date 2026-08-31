# Stitchra — Project Status

> Updated: 31 August 2026

This file records implementation evidence. The locked business blueprint remains authoritative; code presence does not by itself mean a phase has passed review or UAT.

## Current state

- Phase 1–6 hardening is implemented in code with regression-test evidence.
- Production readiness is **not approved**.
- Runtime verification remains blocked until deterministic lockfiles are committed and clean CI completes successfully.

## Phase 1–5 evidence

- Core: serialized numbering/approval, tenant isolation, append-only audit, route permissions, expiring API tokens.
- Master Data: tenant-scoped CRUD/import, schema-aligned validation, deletion safeguards.
- Sales/Product Development: locked BOM/routing/cost versions, SO matrix integrity, transactional transitions.
- Inventory/Purchasing/Receiving: ITS locking/idempotency, exact dimensions, per-roll QC, valuation preservation, strict 3-way match.
- MRP/Production: strict SO selection, atomic shortage→PR, unique MO generation, dimensional hard reservation, exact issue, incremental backflush.

## Phase 6 evidence

- Cutting quantity is capped by SO matrix under MO lock.
- Marker usage is exact-roll and cannot exceed prior material issue.
- Actual consumption is stored per MO allocation without mutating approved BOM.
- Bundle generation is serialized and exact.
- Scan state transitions lock bundle/MO, reject duplicate directions, and are append-only.
- Finishing requires completed sewing routing; WIP/output queries are tenant-scoped.
- Rework uses active defect library and explicit resolution.

These items are implementation evidence only. Tests have **not** been declared green in this environment.

## Known gaps

- BR-042 leftover roll needs explicit warehouse/dispatched/consumed/returned quantity states.
- Offline scan replay needs a client idempotency key and conflict handling.
- Browser auth and shop-floor device-token flows are not yet separated.

## Phase status

| Phase | Implementation evidence | Review status |
|---|---|---|
| 1–3 | Core, Master Data, Sales/PD hardening present | CI/runtime verification pending |
| 4 | Inventory/Purchasing/Receiving hardening present | CI and real concurrency verification pending |
| 5 | MRP/MO/reservation/issue hardening present | BR-042 plus CI/concurrency pending |
| 6 | Cutting/scan/finishing/rework hardening present | Offline/device design plus CI/concurrency pending |
| 7 | QC/Packing/Shipment/Subcontracting broadly implemented | Dedicated audit required |
| 8 | Costing/Finance broadly implemented | Accounting validation required |
| 9 | Dashboard/Reporting/Hardening in progress | Not approved |

## Immediate blockers

1. Generate and commit `apps/api/composer.lock` and `apps/web/package-lock.json` from current manifests.
2. Run full PHP/web CI from a clean checkout and retain evidence.
3. Add real multi-process concurrency tests for counters, stock transitions, MRP, MO release, bundles, and scans.
4. Design BR-042 roll dispatch/consume/return state model.
5. Design offline replay keys and separate browser/device authentication.

## Exit criteria before production

1. Deterministic lockfiles and green CI.
2. Critical multi-process concurrency tests pass on the production DB engine.
3. Cross-company endpoint isolation passes for all tenant-owned resources.
4. Browser/device auth and offline replay are security-reviewed.
5. External secrets, private data services, backups, monitoring, and tested restores.
6. Formal UAT and pilot approval.
