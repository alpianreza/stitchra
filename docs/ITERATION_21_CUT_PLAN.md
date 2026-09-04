# ITERATION 21 — CUT PLAN & CUT PLAN LAYS

Date: 4 September 2026  
Baseline: `95d18dace96072c19963662b87b2b80c79002a58`  
Implementation HEAD before closure: `c658adf8a283a4b4d6581906b4b6ccd54a751504`  
Status: IMPLEMENTED / STATIC READBACK PASS / RUNTIME NOT RUN

## Requirement authority

- Database Blueprint §3.5: `cut_plans` and `cut_plan_lays`.
- Process Flow PF-04: Cut Plan records number of lays, size ratio per lay, and target marker length before Cutting Order.
- Business Specification: PPIC owns Cut Plan using existing `planning.cutplan.*` permissions.

## Commit sequence

- `c19133d865e49796e4fadae2a4c702d0485adeac` — schema, models, service, controller, Cut Order source link.
- `8df258f8d715cf76bc093c280b9767c86c6a4894` — API, PPIC frontend, Production Order relation, implementation document.
- `3c0e79baf197a3b03fb08c5e7d4ea4dbe069777d` — Planning sidebar navigation.
- `c658adf8a283a4b4d6581906b4b6ccd54a751504` — new-execution Cutting frontend directed through Cut Plan.

## Data model

- `cut_plans`: tenant, document number, MO source, planned lay count, derived total quantity.
- `cut_plan_lays`: normalized planned lay sequence, colorway, layer count, and optional estimated marker length.
- `cut_plan_lay_ratios`: normalized size ratio rows. A separate relational child is used instead of JSON so colorway×size quantities remain queryable and traceable.
- `cut_orders.cut_plan_id`: nullable FK for historical compatibility; new plan-backed Cut Orders persist the source plan.

## Data flow

`MO Matrix → Cut Plan → Planned Lay × Layer Count × Size Ratio → derived colorway×size matrix → Cut Order Lines → actual Lay/Lay Roll/Cut Output/Bundle`

## Controls

- New Cut Plans require a RELEASED MO.
- Every planned colorway×size must exist in the MO snapshot; historical MO fallback is explicitly labelled.
- Planned quantity per matrix cell equals `layer_count × ratio_qty`.
- Cumulative plans cannot exceed the MO matrix ceiling.
- A plan can create only one active Cut Order.
- Cut Order quantities are derived by the server from planned lays; client-supplied arbitrary Cut Order lines are not used by the new workbench.
- Existing direct MO→Cut Order endpoint is retained and labelled `LEGACY_DIRECT_MO` for compatibility, but removed from the new-execution Cutting UI.
- Cut Plan and Cut Order share the existing `CUT` numbering counter; no undocumented new numbering prefix was invented.
- No Cut Plan status, approval lifecycle, revision, marker efficiency threshold, or actual-vs-plan variance rule was invented.
- No historical Cut Order or MO is backfilled or rewritten.

## API/UI

- `GET /planning/cut-plans/options`
- `GET /planning/cut-plans`
- `POST /planning/cut-plans/from-mo/{productionOrder}`
- `POST /planning/cut-plans/{cutPlan}/cut-order`
- Frontend `/planning/cut-plans`: planned lay and ratio entry, matrix ceilings, stored plan review, and Cut Order generation.
- Sidebar Planning: `Cut Plan & Planned Lays`.
- Frontend `/production/cutting`: new execution now points operators to the Cut Plan workbench; existing Lay Roll, shade validation, Cut Output, and Bundle execution remains intact.

## Static verification

Static source readback covered migration, models, service, controller, routes, Production Order and Cut Order relationships, Planning workbench, Cutting workbench, and sidebar. The source chain and tenant/quantity guards are consistent.

## Deliberate boundaries

- Actual Lay execution remains in the existing Cutting workbench.
- Planned-lay to actual-lay one-to-one/split/merge variance is not defined and was not inferred.
- Existing Lay Roll actual-consumption authority and BR-053 shade validation are unchanged.
- No test, migration, build, or E2E execution was run per Business Owner instruction.
- Runtime/UAT evidence remains required before production readiness.
