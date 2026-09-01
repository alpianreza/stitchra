# PHASE 6 — SHOP FLOOR EXECUTION

## Implemented

- Locked MO/cut-order creation with SO matrix quantity ceiling.
- Fabric marker usage requires prior issue on the exact MO×roll.
- Actual consumption is stored on MO material allocation; approved BOM snapshots are not mutated.
- Locked, exact bundle generation with database uniqueness.
- Serialized bundle scan state machine with append-only events and unique duplicate backstop.
- Routing predecessor gate and finishing-after-sewing gate.
- Tenant-safe operation, line, employee, WIP, and daily-output boundaries.
- Controlled rework using active defect library plus explicit resolution.
- Regression coverage for quantity, ordering, duplicate, tenant, append-only, and rework invariants.

## Pending before production approval

1. BR-042 needs explicit warehouse/dispatched/consumed/returned roll quantities to prevent leftover double-counting.
2. Browser session and shop-floor device token must be separated and security-reviewed.
3. Offline scan queue needs a client-generated replay/idempotency key and conflict UX.
4. Real multi-process scan and bundle-generation tests must run against the production database engine.
5. Full PHP suite has not run in this environment because deterministic lockfiles are still absent.

## Status

Implementation hardening complete for online cutting, scanning, finishing transition, and rework. Production approval remains blocked by the items above.
