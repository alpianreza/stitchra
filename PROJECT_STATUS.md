# Stitchra — Project Status

> Updated: 31 August 2026

This file records implementation evidence. The locked business blueprint remains authoritative; code presence does not by itself mean a phase has passed review or UAT.

## Current state

- Application code exists across Core, Master Data, Sales, Inventory, MRP/Production, Cutting, Quality, Packing/Shipment, Costing, and Finance domains.
- Automated feature tests exist for the principal domain flows.
- Production readiness is **not yet approved**.
- Phase 1 code hardening is substantially implemented, but runtime verification remains blocked until deterministic lockfiles are committed and CI completes successfully.

## Phase 1 hardening evidence

Implemented on `main`:

- Concurrency-safe document numbering and serialized approval transitions.
- Stale approval-request rejection and post-commit approval events.
- Tenant context validation, cleanup, and cross-company write rejection.
- Append-only audit model protections and recursive sensitive-field redaction.
- Granular permission middleware on domain routes; dynamic Shop Floor and Reporting permissions remain enforced in their controllers.
- Scoped Sanctum API tokens with configurable expiry and device names.
- Docker build failure propagation, Node 24 alignment, MySQL 8.4 pinning, corrected mounts, and removal of the API/MinIO port collision.
- Regression tests added for stale approvals, audit immutability/redaction, cross-company writes, expiring tokens, and real domain-route permission gates.

These items are implementation evidence only. Tests have **not** been declared green in this environment.

## Phase status

| Phase | Implementation evidence | Review status |
|---|---|---|
| 1 — Core Foundation | Core code, hardening, and regression tests present | CI/runtime verification pending |
| 2 — Master Data | Code and feature tests present | Review required |
| 3 — Sales/BOM/Routing/Estimated Costing | Code and feature tests present | Review required |
| 4 — Inventory/Purchasing/Receiving | Code and feature tests present | Concurrency review required |
| 5 — MRP/Planning/MO | Code and feature tests present | Review required |
| 6 — Cutting/Sewing/Finishing/WIP | Partial-to-broad implementation evidence | Device/offline decisions and review required |
| 7 — QC/Packing/Shipment/Subcontracting | Partial-to-broad implementation evidence | Review required |
| 8 — Costing/Finance | Code and feature tests present | Accounting validation required |
| 9 — Dashboard/Reporting/Hardening | In progress | Not approved |

## Immediate blockers

1. Generate and commit `apps/api/composer.lock` and `apps/web/package-lock.json` from the current manifests.
2. Run the full PHP and web CI jobs from a clean checkout and retain evidence of the results.
3. Add real multi-process concurrency tests for numbering, approval, and inventory.
4. Complete the dedicated browser-session versus shop-floor device-token design and security review.

## Exit criteria before production

1. CI is green with deterministic dependency lockfiles.
2. Real multi-process concurrency tests pass for numbering, approval, and inventory.
3. Cross-company endpoint isolation tests pass for every tenant-owned resource.
4. Browser auth and shop-floor device-token flows are separated and security-reviewed.
5. Production Compose/deployment configuration uses external secrets, internal-only data services, backups, monitoring, and tested restore procedures.
6. UAT and pilot production are formally approved by the owner.
