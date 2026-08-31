# Modul Reporting & Dashboard

## Core reports

- `order_status` — tenant-scoped SO quantity and value.
- `wip_summary` — ACTIVE/REWORK bundles by MO and stage.
- `production_efficiency` — final routing-operation OUT only, preventing repeated bundle output across operations.
- `qc_summary` — verdict counts and defect Pareto.
- `stock_aging` — current balance value and balance-row age; this is not FIFO lot age.
- `consumption_variance` — MO allocation actual consumption versus BOM snapshot estimate.
- `otd` — shipped date versus non-null ex-factory date.
- `bep_position` — one latest approved cost sheet per style.

## Security and bounds

- Each report maps to its own domain permission; one reporting permission no longer opens all reports.
- API reports default to 1,000 rows and cap at 5,000.
- Reporting endpoints are rate-limited; export has a stricter limit.
- CSV supports object/array rows, uses explicit PHP 8.5-safe escaping, includes UTF-8 BOM, and neutralizes spreadsheet formulas.
- Unknown reports return 404; invalid parameters return validation/422 errors.
- Dashboard pending approvals joins role company ownership correctly.

## KPI correctness

Today output counts a bundle only at the final sewing routing operation. QC 7-day pass rate uses the latest final inspection per MO. All KPI queries are company-scoped.

Runtime/CI has not been declared green because deterministic lockfiles and clean test execution are still unavailable.
