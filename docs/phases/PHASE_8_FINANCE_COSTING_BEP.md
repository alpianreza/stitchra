# PHASE 8 — FINANCE, ACTUAL COSTING & BEP

## Implemented hardening

- Valid composite GL period foreign key and serialized period lifecycle.
- Tenant-safe balanced journals, immutable correction by unique reversal, and correct trial-balance netting.
- Race-safe auto-journal posting key with amount-conflict detection.
- Locked, tenant-safe AR invoice creation and AR/AP partial payment allocation.
- One AR invoice per shipment plus positive amount/exchange-rate database checks.
- Payment journal period derived from payment date.
- Actual costing uses final-operation output once per bundle and historical material issue cost.
- Required line/OH rates fail clearly when missing; subcon fees are company/MO scoped.
- BEP validates period/input and selects one latest approved cost sheet per style.
- Regression tests cover balance, closed periods, reversal, auto-post idempotency conflict, AR/AP flow, aging, actual costing, and BEP.

## Pending before production approval

1. Full clean PHP test suite and migrations have not run because deterministic lockfiles are absent.
2. Real multi-process tests are required for first-period creation, auto-posting, reversal, AR invoice creation, and payments.
3. MO should eventually snapshot the approved standard cost sheet id; current variance uses latest approved style sheet.
4. Tax, withholding, FX revaluation, bank reconciliation, and formal period-close checklist require accounting sign-off.
5. Financial statement mapping/sign conventions require accountant/UAT validation.

## Status

Implementation hardening complete; accounting sign-off, CI, migration smoke test, concurrency verification, and UAT remain pending.
