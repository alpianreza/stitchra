# Stitchra — Iteration 18 Runtime Verification & Stabilization

**Date:** 4 September 2026  
**Approved by:** Business Owner  
**Repository:** `alpianreza/stitchra`  
**Baseline:** `f3140fdcb1c16b7e56eded402539f173c2fbe920`  
**Mode:** Verification and stabilization only; no new business rule.

## Objective

Prove the current connected operational backbone at runtime before adding Planning, MO Matrix, Packing Instruction, export, Subcontract WIP, or PSO capabilities.

## Verification scope

1. MySQL 8.4 fresh migration and seed across all committed migrations, including D-08 migration `000032` and USD/FX migration `000035`.
2. PHP syntax and full Pest suite.
3. Focused USD/IDR Sales Order snapshot coverage:
   - implicit company base USD uses rate 1;
   - explicit USD uses rate 1;
   - supplied IDR reciprocal rate is frozen on the SO;
   - dated IDR master-rate fallback;
   - missing foreign rate fails closed;
   - non-1 base-currency rate fails closed.
4. Deterministic web dependency install, TypeScript check, and Next.js production build.
5. Optional Playwright login smoke.
6. No claim of concurrency, accounting UAT, production-state migration, or end-to-end factory-flow success without executed evidence.

## Added verification assets

- `apps/api/tests/Feature/SalesOrderCurrencyPolicyTest.php`
- `scripts/verify-runtime.sh`

The verification script refuses to reset a database unless:

- `ALLOW_DATABASE_RESET=1` is explicitly supplied;
- `apps/api/.env.ci` declares `APP_ENV=testing`;
- the database is named `stitchra_test`.

Run the full gate only against a disposable MySQL database:

```bash
ALLOW_DATABASE_RESET=1 RUN_E2E=1 bash scripts/verify-runtime.sh
```

Run without Playwright:

```bash
ALLOW_DATABASE_RESET=1 bash scripts/verify-runtime.sh
```

## Existing GitHub CI

The existing `.github/workflows/ci.yml` already runs on pushes to `main` and performs:

- MySQL 8.4 + Redis service startup;
- Composer install;
- `php artisan migrate --seed --force`;
- full Pest suite;
- npm install and Next.js build.

The new Sales Order currency tests are automatically included by the existing full Pest command. A proposed workflow hardening update—manual dispatch, explicit PHP syntax, latest-migration replay, `npm ci`, explicit TypeScript, and Playwright smoke—could not be committed because the current GitHub connection can write repository source but does not have permission to modify workflow files.

## Evidence status

- Source/test readback: **PASS**.
- New focused test scenarios: **COMMITTED, NOT YET OBSERVED RUNNING**.
- Fresh migration execution: **NOT OBSERVED**.
- Full Pest: **NOT OBSERVED**.
- TypeScript/Next build: **NOT OBSERVED**.
- Playwright: **NOT RUN by existing workflow**.
- Production database migration `000032` reconciliation: **REQUIRES CONTROLLED CLONE TEST**.
- Concurrency and accounting UAT: **PENDING**.

## Stop conditions

Stop and report before feature work when any of these occurs:

- migration or rollback failure;
- D-08 orphan-table reconciliation mismatch;
- missing Composer lock or non-deterministic dependency resolution blocks reproducibility;
- BR-042 quantity invariant failure;
- Lay Roll vs Marker authority regression;
- ACTUAL/BACKFLUSH overlap;
- named-output fallback to legacy `qty_produced`;
- USD/IDR rate inversion or precision loss;
- WIP/FG/Shipment valuation mismatch;
- closed-period journal mutation;
- tenant isolation failure.

## Current decision

Production readiness remains **NO-GO** until the runtime outputs are collected and all P0 failures are resolved. No Planning or new operational entity implementation should begin merely because the verification harness exists.
