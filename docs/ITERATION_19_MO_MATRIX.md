# Stitchra — Iteration 19: Manufacturing Order Matrix

**Date:** 4 September 2026  
**Baseline:** `82f0eec84709886b15030801c6f6219a614d97ee`  
**Scope:** Close the BR-020 `Sales Order matrix → Manufacturing Order matrix → Cutting matrix` data break.

## Implemented authority

- `mo_lines` stores one row per MO colorway × size.
- Every new row keeps an explicit `sales_order_line_id` source reference.
- New MOs remain one header per Sales Order style; their `qty_planned` equals the sum of persisted matrix rows.
- The matrix is copied only while creating the MO from a CONFIRMED Sales Order.
- Release fails closed when a persisted matrix total differs from the MO header quantity.
- Cutting uses `mo_lines` as its quantity ceiling whenever the matrix exists.
- Existing historical MOs are not backfilled or rewritten. If they do not have `mo_lines`, Cutting retains the existing Sales Order matrix fallback and the API/UI labels it `LEGACY_SO_FALLBACK`.

## Repository changes

- Migration: `apps/api/database/migrations/2026_09_04_000036_create_mo_lines.php`
- Model: `apps/api/app/Modules/Production/Models/MoLine.php`
- Relationship: `ProductionOrder::matrixLines()`
- Creation and release integrity: `ProductionOrderService`
- Read API: `GET /production/orders/{productionOrder}/matrix`
- Cutting convergence: `CuttingService::create()`
- UI: `apps/web/src/app/(app)/production/orders/[id]/mo-matrix-panel.tsx`

## Data integrity

- Foreign keys use `RESTRICT`.
- Matrix combination is unique per MO.
- Source Sales Order line is unique per MO.
- Quantity must be greater than zero.
- Company is persisted on every MO matrix row and protected by the existing company scope.
- No historical UPDATE, inferred backfill, new status, new numbering scheme, or parallel production transaction was introduced.

## Verification status

Per Business Owner instruction, runtime tests, migration execution, Pest, TypeScript, Next build, and E2E were not run in this iteration. Source files must still be read back and checked statically before the iteration is reported complete. Production readiness remains NO-GO until the owner-run runtime verification is completed.
