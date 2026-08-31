# Stitchra — Project Status

> Updated: 31 August 2026

This file records implementation evidence. The locked business blueprint remains authoritative; code presence does not by itself mean a phase has passed review or UAT.

## Current state

- Application code exists across Core, Master Data, Sales, Inventory, MRP/Production, Cutting, Quality, Packing/Shipment, Costing, and Finance domains.
- Automated feature tests exist for the principal domain flows.
- Production readiness is **not yet approved**.
- Phase 1–4 hardening is implemented in code, but runtime verification remains blocked until deterministic lockfiles are committed and CI completes successfully.

## Phase 1 hardening evidence

- Concurrency-safe numbering and serialized approval transitions.
- Stale approval rejection and post-commit events.
- Tenant context validation, cross-company write rejection, append-only audit, granular permissions, and expiring API tokens.
- Corrected deterministic container build behavior and service networking.

## Phase 2 hardening evidence

- Safe search and validated pagination/filter input.
- Shared tenant-scoped CRUD/import validation and composite uniqueness.
- Hardened CSV handling, BR-003 material tracking, positive master rates, and referenced-master deletion guards.

## Phase 3 hardening evidence

- Locked BOM/routing/cost-sheet versioning and approval transitions.
- Tenant-safe Product Development and Sales boundaries.
- Strict costing inputs, SO matrix integrity, and transactional submit/confirm flows.
- Regression tests for rollback, tenant/matrix, costing inputs, versioning, and confirmation gates.

## Phase 4 hardening evidence

- ITS movement whitelist, source idempotency, deterministic balance locking, append-only ledger, and direct tenant integrity validation.
- Reserved issue, quality-hold return, corrected QUALITY_RELEASE constraint, and transfer cost preservation.
- Locked/idempotent transfer, adjustment, opname, PR, and PO transitions with approval rollback.
- GR derives material/UOM/cost from locked PO lines and atomically rejects over-receipt.
- Fabric receipt and inward QC now operate on the same per-roll balance key.
- QC finalize is server-derived, locked, and idempotent.
- Three-way match rejects missing receipts, foreign PO lines, supplier mismatch, duplicates, and out-of-tolerance values.
- Regression tests cover atomicity, idempotency, valuation, tenant isolation, receiving, QC, and matching scenarios.

These items are implementation evidence only. Tests have **not** been declared green in this environment.

## Phase status

| Phase | Implementation evidence | Review status |
|---|---|---|
| 1 — Core Foundation | Core hardening and regression tests present | CI/runtime verification pending |
| 2 — Master Data | Validation/import/delete hardening and tests present | CI/runtime verification pending |
| 3 — Sales/BOM/Routing/Estimated Costing | State, tenant, versioning, costing hardening and tests present | CI/runtime verification pending |
| 4 — Inventory/Purchasing/Receiving | Ledger, transaction, receiving, QC, and matching hardening present | CI/runtime and real concurrency verification pending |
| 5 — MRP/Planning/MO | Code and feature tests present | Review required |
| 6 — Cutting/Sewing/Finishing/WIP | Partial-to-broad implementation evidence | Device/offline decisions and review required |
| 7 — QC/Packing/Shipment/Subcontracting | Partial-to-broad implementation evidence | Review required |
| 8 — Costing/Finance | Code and feature tests present | Accounting validation required |
| 9 — Dashboard/Reporting/Hardening | In progress | Not approved |

## Immediate blockers

1. Generate and commit `apps/api/composer.lock` and `apps/web/package-lock.json` from the current manifests.
2. Run full PHP and web CI from a clean checkout and retain evidence.
3. Add real multi-process concurrency tests for numbering, approval, inventory, receiving, and version generation.
4. Complete dedicated browser-session versus shop-floor device-token design and security review.

## Exit criteria before production

1. CI is green with deterministic dependency lockfiles.
2. Real multi-process concurrency tests pass for numbering, approval, inventory, receiving, and versioning.
3. Cross-company endpoint isolation tests pass for every tenant-owned resource.
4. Browser auth and shop-floor device-token flows are separated and security-reviewed.
5. Production deployment uses external secrets, internal-only data services, backups, monitoring, and tested restores.
6. UAT and pilot production are formally approved by the owner.
