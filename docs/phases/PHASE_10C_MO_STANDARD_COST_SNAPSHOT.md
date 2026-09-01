# Stage 10C — MO Standard Cost Snapshot

## Implemented

- Nullable legacy-compatible FK from MO to cost sheet.
- Immutable JSON value snapshot plus SHA-256 integrity hash.
- Automatic snapshot at MO creation when an exact approved sheet exists.
- Mandatory exact BOM/routing cost sheet at MO release.
- Snapshot retained through unrelease and all later lifecycle states.
- Actual-cost variance sourced from snapshot rather than latest style cost sheet.
- One-time stable legacy attachment for historical/manual MOs.

## Snapshot components

Fabric, trim, labor/CM, overhead, subcontract, other, manufacturing total, FOB, margin, cost-sheet document/version, BOM version, and routing version.

## Deployment caveat

Migration `000017` is additive and leaves historical rows empty. Historical MOs receive a stable snapshot at first costing, but Finance should review and explicitly backfill material historical MOs before production reporting. Clean migration and full tests remain pending.
