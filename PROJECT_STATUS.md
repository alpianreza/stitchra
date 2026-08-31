# Stitchra — Project Status

> Updated: 31 August 2026

This file records implementation evidence. The locked business blueprint remains authoritative; code presence does not by itself mean a phase has passed review or UAT.

## Current state

- Application code exists across Core, Master Data, Sales, Inventory, MRP/Production, Cutting, Quality, Packing/Shipment, Costing, and Finance domains.
- Automated feature tests exist for the principal domain flows.
- Production readiness is **not yet approved**.
- Phase 1–3 hardening is implemented in code, but runtime verification remains blocked until deterministic lockfiles are committed and CI completes successfully.

## Phase 1 hardening evidence

- Concurrency-safe numbering and serialized approval transitions.
- Stale approval rejection and post-commit events.
- Tenant context validation, cleanup, and cross-company write rejection.
- Append-only audit protection and sensitive-field redaction.
- Granular server-side permissions and scoped, expiring API tokens.
- Corrected deterministic container build behavior and service networking.

## Phase 2 hardening evidence

- Safe generic search and validated pagination/filter input.
- Shared tenant-scoped CRUD/import validation.
- Composite uniqueness aligned with database constraints.
- Hardened CSV headers, row limits, PHP 8.5 parsing, and error disclosure.
- BR-003 material tracking and positive master rate constraints.
- Deletion guards for referenced critical master records.

## Phase 3 hardening evidence

- BOM/routing version creation and approval transitions use row locks.
- BOM/routing submit rolls back status if approval creation fails.
- Product Development HTTP boundaries validate tenant ownership and return domain errors as `422`.
- Costing rejects missing/zero material prices, line rates, overhead rates, and SAM.
- Cost-sheet versions are serialized and protected by a composite unique index.
- Sales Order creation validates tenant ownership, colorway/style consistency, matrix uniqueness, and creator company access.
- SO submit and confirm use locked, transactional state transitions.
- Regression tests cover Stage 3 rollback, tenant/matrix, missing-cost-input, versioning, exact costing, and confirmation-gate scenarios.

These items are implementation evidence only. Tests have **not** been declared green in this environment.

## Phase status

| Phase | Implementation evidence | Review status |
|---|---|---|
| 1 — Core Foundation | Core hardening and regression tests present | CI/runtime verification pending |
| 2 — Master Data | Validation/import/delete hardening and tests present | CI/runtime verification pending |
| 3 — Sales/BOM/Routing/Estimated Costing | State, tenant, versioning, costing hardening and tests present | CI/runtime verification pending |
| 4 — Inventory/Purchasing/Receiving | Code and feature tests present | Concurrency review required |
| 5 — MRP/Planning/MO | Code and feature tests present | Review required |
| 6 — Cutting/Sewing/Finishing/WIP | Partial-to-broad implementation evidence | Device/offline decisions and review required |
| 7 — QC/Packing/Shipment/Subcontracting | Partial-to-broad implementation evidence | Review required |
| 8 — Costing/Finance | Code and feature tests present | Accounting validation required |
| 9 — Dashboard/Reporting/Hardening | In progress | Not approved |

## Immediate blockers

1. Generate and commit `apps/api/composer.lock` and `apps/web/package-lock.json` from the current manifests.
2. Run full PHP and web CI from a clean checkout and retain evidence.
3. Add real multi-process concurrency tests for numbering, approval, inventory, and version generation.
4. Complete dedicated browser-session versus shop-floor device-token design and security review.

## Exit criteria before production

1. CI is green with deterministic dependency lockfiles.
2. Real multi-process concurrency tests pass for numbering, approval, inventory, and versioning.
3. Cross-company endpoint isolation tests pass for every tenant-owned resource.
4. Browser auth and shop-floor device-token flows are separated and security-reviewed.
5. Production deployment uses external secrets, internal-only data services, backups, monitoring, and tested restores.
6. UAT and pilot production are formally approved by the owner.
