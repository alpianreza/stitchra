# PHASE 7 — QC, PACKING, SHIPMENT, SUBCONTRACTING

## Implemented hardening

- Serialized QC cycles, tenant-safe defect evidence, lifecycle gates, and database cycle uniqueness.
- Locked carton sequencing, exact SO matrix validation, cumulative packing ceilings, and idempotent FG receipt source.
- One shipment per packing list, cumulative per-matrix tolerance, locked FG issue, and per-matrix SO closure.
- Tenant-safe subcontract send/receive, strict line identity, quantity checks, and independent source ids for partial returns.
- Regression tests for pending QC cycles, inactive defects, duplicate carton matrix, duplicate shipment, AQL, FG flow, tolerance override, and repeated subcon returns.

## Pending before production approval

1. Full PHP suite has not run because deterministic lockfiles are absent.
2. Real multi-process tests are still needed for QC cycle, carton sequence/finalize, shipment create/ship, and subcon receive.
3. AQL lookup must receive formal QA sign-off against the exact buyer/ISO tables used in production.
4. Attachment/photo storage policy and malware/access controls need deployment review.

## Status

Implementation hardening complete; CI, concurrency verification, formal AQL validation, and UAT remain pending.
