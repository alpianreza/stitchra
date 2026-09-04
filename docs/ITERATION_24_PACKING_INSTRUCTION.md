# Iteration 24 — Packing Instruction from Sales Order

Status: **IMPLEMENTED / STATIC READBACK PASS / RUNTIME NOT RUN**

## Locked implementation scope

Business Owner approved the safe scope on 4 Sep 2026:

- Packing Instruction belongs to a Sales Order and is versioned.
- Supported types are `SOLID`, `RATIO`, and `MIXED`.
- `SOLID` allows one instruction-approved SKU per carton.
- `RATIO` and `MIXED` use an explicit matrix template; each carton must match the complete template using one positive integer multiplier.
- New Packing Lists fail closed when the Sales Order has no active instruction.
- Packing List stores an immutable instruction-version reference.
- Existing QC FINAL PASS, SO tolerance, tenant, locking, audit, and ITS FG controls remain authoritative.
- Direct Bundle/Finishing Output to Carton allocation remains `NOT_DEFINED` and is not inferred.

## Implementation

Migration `2026_09_04_000041_add_packing_instructions.php` adds versioned instruction headers, normalized matrix lines, and a nullable snapshot FK on Packing List for historical compatibility.

`PackingInstructionService` owns version creation, SO-matrix validation, Packing List snapshot selection, exact carton-template validation, and finalize-time revalidation. Historical Packing Lists without a snapshot keep the legacy QC/matrix boundary and are not backfilled.

API endpoints expose eligible SOs, active instruction read/create, instruction-aware Packing List creation, carton creation, and finalization using existing packing permissions.

The `/packing/instructions` workbench lets operators select a confirmed/in-progress SO, define SOLID/RATIO/MIXED matrix quantities, review the active version, and publish a new immutable version. Packing pages share navigation between instruction setup and Packing List execution.

## Deliberate boundary

No Bundle, Finishing Output, FIFO, or piece allocation is created. Traceability remains QC FINAL PASS → Packing Instruction snapshot → Carton matrix → FG receipt.

## Verification

Static readback passed for migration, models, service, controllers, routes, Packing List snapshot relation, workbench, and navigation. Runtime migration, Pest, TypeScript, Next build, API/E2E, and concurrency verification are NOT RUN and must not be claimed passed.
