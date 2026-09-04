# ITERATION 20 — PRODUCTION PLAN & LINE LOADING

Date: 4 September 2026  
Baseline: `af80ec7e4002a6712c499e3f450d0482aa14da22`  
Status: IMPLEMENTED / STATIC VERIFICATION PENDING / RUNTIME NOT RUN

## Requirement authority

- `ERP_GARMENT_BUSINESS_SPECIFICATION.md`: Planning includes Production Plan and capacity/line loading; PPIC report requires line loading vs capacity.
- `ERP_GARMENT_DATABASE_BLUEPRINT.md` §3.5: `production_plans` and `line_loading`.
- `ERP_GARMENT_PROCESS_FLOW.md` PF-01 step 6: PPIC composes production plan and line loading before production execution.
- Existing permissions are reused: `planning.production.view|create|update`.

## Implemented data flow

`CONFIRMED Sales Order + Style → Production Plan (Line + Period + Target) → daily Line Loading (MO + Qty + capacity snapshot) → Production Order line/planned dates`

## Controls

- Tenant/company scope is enforced on both transactional models and revalidated in the service.
- Production Plan style must exist in its confirmed SO matrix.
- Only active same-company lines are accepted.
- A Line Loading must use a PLANNED MO with the same SO and style as the plan.
- Loading dates must be inside the plan period.
- Cumulative loading cannot exceed the plan target or MO planned quantity.
- The existing single `production_orders.line_id` authority is preserved; a MO cannot be split across different lines.
- MO `line_id`, `planned_start`, and `planned_end` are synchronized from persisted loading rows.
- Line `capacity_std` is snapshotted on each loading row. Daily load, variance, percentage, and overload warning are derived from persisted data.
- Capacity overload is report-only, not a hard block. Working calendar, overtime, shift, holiday, and split-line policy are not defined and were not invented.
- Production Plan has no invented document number, status lifecycle, or approval flow because the locked blueprint does not define those fields and existing RBAC only defines view/create/update.
- No historical MO, plan, or transaction is backfilled or rewritten.

## API

- `GET /planning/production-plans/options`
- `GET /planning/production-plans`
- `POST /planning/production-plans`
- `PUT /planning/production-plans/{productionPlan}`
- `POST /planning/production-plans/{productionPlan}/loadings`
- `PUT /planning/line-loadings/{lineLoading}`
- `GET /planning/line-loading/capacity`

## UI

`apps/web/src/app/(app)/planning/production-plans/page.tsx` provides:

- Production Plan entry from confirmed SO/style and active line.
- Daily MO Line Loading entry.
- Plan target versus loaded quantity.
- Line loading versus snapshotted capacity with explicit overload warning.

## Deliberate boundaries

- No Cut Plan implementation; that remains Iteration 21.
- No SO Amendment/MRP delta implementation.
- No calendar, shift, overtime, finite-capacity sequencing, automatic optimizer, or split-MO policy.
- Existing MO creation from confirmed SO is preserved for compatibility.
- No tests, build, or migration execution were run per Business Owner instruction. Runtime remains manually owned by the Business Owner.
