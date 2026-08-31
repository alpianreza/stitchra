# Stitchra — Project Status

> Updated: 31 August 2026

This file records implementation evidence. The locked business blueprint remains authoritative; code presence does not by itself mean a phase has passed review or UAT.

## Current state

- Application code exists across Core, Master Data, Sales, Inventory, MRP/Production, Cutting, Quality, Packing/Shipment, Costing, and Finance domains.
- Automated feature tests exist for the principal domain flows.
- Production readiness is **not yet approved**.
- Phase 1–5 hardening is implemented in code, but runtime verification remains blocked until deterministic lockfiles are committed and CI completes successfully.

## Phase 1–3 hardening evidence

- Concurrency-safe numbering/approval, tenant isolation, append-only audit, route permissions, and expiring API tokens.
- Tenant-scoped Master Data validation/import/deletion safeguards.
- Locked BOM/routing/cost versioning, strict costing inputs, SO matrix integrity, and transactional document transitions.

## Phase 4 hardening evidence

- ITS movement whitelist, source idempotency, deterministic balance locking, append-only ledger, and direct tenant validation.
- Reserved issue, quality-hold return, corrected QUALITY_RELEASE constraint, and transfer cost preservation.
- Locked/idempotent inventory operations, GR over-receipt prevention, server-derived PO values, per-roll stock/QC, and strict 3-way matching.

## Phase 5 hardening evidence

- MRP run numbering is serialized and requirement rows are unique per run/material.
- MRP rejects partial/foreign/non-confirmed SO selections and converts shortage→PR atomically under lock.
- MO creation locks the SO and enforces one MO per SO×style with database uniqueness.
- MO release allocates reservation across actual location/lot/roll balances and rejects shortage before mutation.
- Unrelease restores exact balance dimensions and is blocked after issue.
- Material issue uses the exact reservation dimension; backflush posts cumulative deltas and updates reservation state.
- Fabric inventory now uses consumption UOM (meter) while preserving PO valuation through converted unit cost.
- Regression tests cover MRP atomicity, per-roll reservation/issue, incremental backflush, shortage rollback, and duplicate protection.

These items are implementation evidence only. Tests have **not** been declared green in this environment.

## Known functional gap

- BR-042 leftover roll requires an explicit dispatch/consumption/return quantity model. The current method is not approved for production because it can double-count warehouse stock without that state separation.

## Phase status

| Phase | Implementation evidence | Review status |
|---|---|---|
| 1 — Core Foundation | Core hardening and regression tests present | CI/runtime verification pending |
| 2 — Master Data | Validation/import/delete hardening and tests present | CI/runtime verification pending |
| 3 — Sales/BOM/Routing/Estimated Costing | State, tenant, versioning, costing hardening and tests present | CI/runtime verification pending |
| 4 — Inventory/Purchasing/Receiving | Ledger, transaction, receiving, QC, and matching hardening present | CI/runtime and real concurrency verification pending |
| 5 — MRP/Planning/MO | MRP, dimensional reservation, issue, and backflush hardening present | BR-042 design plus CI/concurrency verification pending |
| 6 — Cutting/Sewing/Finishing/WIP | Partial-to-broad implementation evidence | Device/offline decisions and review required |
| 7 — QC/Packing/Shipment/Subcontracting | Partial-to-broad implementation evidence | Review required |
| 8 — Costing/Finance | Code and feature tests present | Accounting validation required |
| 9 — Dashboard/Reporting/Hardening | In progress | Not approved |

## Immediate blockers

1. Generate and commit `apps/api/composer.lock` and `apps/web/package-lock.json` from the current manifests.
2. Run full PHP and web CI from a clean checkout and retain evidence.
3. Add real multi-process concurrency tests for numbering, approval, inventory, receiving, MRP, and MO release.
4. Design and implement explicit roll dispatch/consumption/return state for BR-042.
5. Complete dedicated browser-session versus shop-floor device-token design and security review.

## Exit criteria before production

1. CI is green with deterministic dependency lockfiles.
2. Real multi-process concurrency tests pass for critical counters and stock transitions.
3. Cross-company endpoint isolation tests pass for every tenant-owned resource.
4. Browser auth and shop-floor device-token flows are separated and security-reviewed.
5. Production deployment uses external secrets, internal-only data services, backups, monitoring, and tested restores.
6. UAT and pilot production are formally approved by the owner.
