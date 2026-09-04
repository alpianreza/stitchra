# ITERATION 22 — ORDER AMENDMENT & MRP DELTA

Date: 4 September 2026  
Baseline: `da28268120abe9864b6fb28f43ca81f69d0ca725`  
Implementation HEAD before closure: `f0e25ffebea5ae0d8e89b3a36922805854ba4539`  
Status: IMPLEMENTED / STATIC READBACK PASS / RUNTIME NOT RUN

## Authority

- BR-022 / OBD-021 default: amendment is allowed before cutting starts and triggers MRP delta.
- PF-01a / BP-08: SO quantity, ratio, or ex-factory change must be traceable and followed by MRP delta.
- Historical transactions must not be rewritten.

## Commit sequence

- `1b66dd3271f87e9a31b1bae0b356496d323b11ee` — schema, normalized amendment lines, service, MRP delta fields, and models.
- `f0e25ffebea5ae0d8e89b3a36922805854ba4539` — API, frontend workbench, and implementation document.

## Implementation

- Normalized `order_amendment_lines` stores source SO line, old qty, new qty, and signed delta.
- Amendment header stores old/new ex-factory dates, apply actor/time, and baseline/delta MRP references.
- MRP runs identify `FULL`, `AMENDMENT_BASELINE`, or `AMENDMENT_DELTA` and persist baseline gross/net plus signed gross/net delta per material.
- Draft creation snapshots current SO values. Apply fails on concurrent value changes.
- Apply runs baseline MRP, updates the confirmed SO, then runs MRP again in one transaction.
- Amendment is blocked once any related MO reaches CUTTING or a downstream production status.
- SO remains CONFIRMED because `AMENDED` is not in the locked SO status set; amendment history is the state evidence.
- Existing SO numbering counter is reused instead of inventing an undefined amendment prefix.

## API/UI

- `GET /sales/amendments`
- `POST /sales/orders/{salesOrder}/amendments`
- `POST /sales/amendments/{orderAmendment}/apply`
- Frontend `/sales/amendments` supports draft quantity/date changes, apply, and material delta review.

## Static verification

Static source readback covered migration ordering and FK teardown, amendment models, MRP models, transactional service, concurrency conflict flags, controller validation, routes, and frontend payload/result mapping. The persisted before/after MRP chain is internally consistent.

## Boundary

MRP delta is persisted evidence only. PR, PO, Production Plan, MO, and Cut Plan are not automatically mutated because adjustment, cancellation, supplier communication, and released-MO reconciliation rules are not defined. Existing matrix combinations can change quantity; adding/removing SKU combinations is deferred. No test, migration, build, or E2E execution was run per Business Owner instruction.
