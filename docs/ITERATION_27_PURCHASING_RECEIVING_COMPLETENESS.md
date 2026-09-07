# Iteration 27 — Purchasing & Receiving Completeness

Status: **IMPLEMENTED / TARGETED RUNTIME PASS / FULL REGRESSION NOT GREEN**

Verified: 2026-09-07. Baseline: `e99a3a65b62dcad8e9b9424f5a8d9f303e7d8195` (Iteration 26).

This is an implementation record for the user's Iteration 27 backlog, not a new business-rule authority or production approval. [Business Rules](./ERP_GARMENT_BUSINESS_RULES.md), [Decision Log](./DECISION_LOG.md), and the existing PO approval flow remain authoritative.

## Scope delivered

| Area | Operational flow | Boundary |
|---|---|---|
| Purchasing | RFQ requirements → supplier quotations → manual comparison/award → PO `DRAFT` | One whole-RFQ award to one supplier; no automatic winner or approval bypass |
| Supplier return | Finalized inward QC `FAIL` → return `DRAFT` → explicit post → `SHIPPED` | Whole failed receipt unit/roll; physical return only, no financial settlement |
| Putaway | Finalized inward QC `PASS` → putaway `DRAFT` → explicit post → `POSTED` | Initial putaway within the receipt warehouse; whole fabric roll, partial non-roll allowed |
| Trace | GR → immutable receipt ledger → normalized operation line → location movements/current dimension balances | Non-roll balances are pooled by stock dimension, not piece-level receipt provenance |

### RFQ, quotation, comparison, and PO

- RFQ stores normalized material/UOM/quantity requirements and invited suppliers. An optional PR-line reference must belong to an `APPROVED` PR in the same company and match material, UOM, and allowed quantity.
- Supplier quotations must cover every RFQ line. Quantity and UOM come from the RFQ; client values cannot replace them.
- Each quotation snapshots its supplier reference, quotation date, optional validity date, currency, exchange rate, company base currency, price, lead time, and terms.
- Comparison shows native totals, comparable base-currency totals, and line prices. It never selects a winner automatically.
- Award requires a human selection and reason. It checks quotation date validity and complete, unchanged source snapshots, then creates a PO `DRAFT` through the existing purchasing service.
- PO header and lines retain RFQ/quotation/optional PR provenance. The PO list links back to its source RFQ.
- The RFQ row lock, unique PO source constraints, and transaction protect against a second award. Repeating the same award returns the same PO; changing its quotation or award payload is rejected.
- `OPEN → CLOSED` stops new quotations but still permits award of an eligible quotation; award ends in `AWARDED`. Quote editing, quote revisions, split awards, partial quotations, and cumulative PR procurement allocation are not introduced.
- PO submission and configured approval remain separate, existing actions. Direct PO creation cannot forge received quantity or sourcing lineage.

### QC authority and supplier return

- Inward QC derives warehouse, location, lot, quantity, UOM, and cost from the immutable `PURCHASE_RECEIPT` ledger entry. It no longer releases stock against missing/default location or lot dimensions.
- Finalization is transactional and replay-safe. A second finalized decision for the same receipt unit is rejected. Inspecting only some fabric rolls does not release untouched rolls from `QUALITY_HOLD`.
- Supplier return eligibility requires persisted, finalized QC `FAIL`, not merely the legacy `REJECTED_RETURNED` status label.
- New returns have normalized lines referencing the original receipt-ledger ID and exact GR line/roll. Client quantity, cost, location, or lot overrides are not accepted as authority.
- Creating a draft allocates the failed unit but does not move stock. Explicit post uses ITS `PURCHASE_RETURN`, records `posted_at`, and changes the return to `SHIPPED`.
- Duplicate active returns, already-returned stock, contradictory putaway allocation, missing receipt authority, and tenant mismatches fail closed.
- Reposting a posted document does not create another stock movement. Draft cancellation only releases the document allocation; posted returns cannot be cancelled through this flow.
- Historical return ledger entries are recognized even when normalized lines are absent. Legacy headers without normalized lines are not silently reposted.
- Returned fabric roll remaining quantities are set to zero after successful physical posting.

**Business boundary:** the existing immediate operational-return behavior is exposed as draft plus explicit posting; this is not a newly approved maker/checker matrix. Return approval levels, partial rejection, supplier claim valuation, credit/debit notes, AP/GL settlement, PO reopening, and gross-to-net received-quantity policy remain outside this iteration. PO cumulative gross receipts are not decremented. The legacy `REJECTED_RETURNED` QC label alone still does not prove shipment; use the return document, `posted_at`, and ledger evidence.

### Putaway and trace

- A putaway line references an immutable receipt unit, its original location, a different destination location in the same active warehouse, and an allocated quantity.
- Only finalized QC `PASS` units are eligible. Failed, pending, returned, or return-allocated units are rejected.
- Active draft plus posted allocations cannot exceed the original receipt quantity. Fabric must move as a whole roll; non-roll materials can be allocated partially to multiple locations.
- Drafts allocate receipt quantity but are not inventory reservations. Available stock, including existing reservations, is checked again at posting.
- Posting locks the stock dimension/balance, snapshots the source moving-average cost, and writes paired `TRANSFER_OUT` / `TRANSFER_IN` through ITS in one transaction. No direct stock-balance writes are added.
- Both sides preserve stock dimensions other than location, stock quantity, and inventory value. Failure of the inbound side rolls back the outbound side and document valuation snapshot.
- Reposting is idempotent. Draft cancellation frees allocation only; arbitrary relocation, inter-warehouse transfer, posted reversal, and stock adjustment are not part of this flow.
- Trace exposes receipt authority, QC eligibility, active allocations, saved operation lines, current dimension balances, and a paginated append-only ledger.
- **Non-roll/lot balances are pooled.** Current location balances are labelled as dimension balances; the UI does not claim they uniquely identify physical pieces from one GR. Receipt-to-putaway/return provenance comes from normalized operation lines.

## API, UI, and authorization

New pages:

- `/purchasing/rfqs`: searchable RFQs, requirements, invited suppliers, quotations, comparison, award, and PO handoff.
- `/receiving/supplier-returns`: eligible failed receipt units, draft creation, explicit post/cancel confirmation, and trace.
- `/receiving/putaway`: eligible passed receipt units, quantity/location allocation, explicit post/cancel confirmation, and trace.

Existing GR entry now captures receipt location and non-roll lot. GR, QC, and PO pages provide operational handoffs. QC UI keeps roll and non-roll identities separate and retries a failed finalization against the saved inspection rather than creating another inspection.

All endpoints require authentication and active-company scope. [Permission Map](./PERMISSION_MAP.md#iteration-27-operational-endpoints) records the mappings to **existing** permission codes:

- RFQ read/create/update permissions govern sourcing; award additionally requires `purchasing.po.create`.
- Return/putaway view, create, post, and cancel use `receiving.gr.view/create/submit/update`.
- Warehouse PO lookup exposes quantities, not prices. GR/QC responses mask commercial prices without `purchasing.po.view`.
- Dedicated sourcing, warehouse, and QC lookups avoid requiring broad master-data administration or purchasing-price access for operational users.

These endpoint checks do not substitute for business approval configuration or production RBAC/UAT sign-off.

## Schema and compatibility

Migration: `apps/api/database/migrations/2026_09_07_000044_complete_purchasing_and_receiving.php`.

- Adds `rfq_lines`, `rfq_suppliers`, quotation snapshots/line provenance, and nullable unique PO sourcing links.
- Adds `supplier_return_lines`, `supplier_returns.posted_at`, `putaways`, and `putaway_lines`.
- Registers separate `SR` and `PUT` numbering prefixes for existing companies; the seeder includes them for new environments.
- Preserves historical PO and quotation rows through nullable links/snapshots. No guessed backfill, price conversion, or reconstruction of missing receipt authority is performed.
- Old return movements are read compatibly. Ambiguous or incomplete legacy records require reconciliation rather than invented provenance.
- Clean MySQL migration-chain execution and rollback/reapply of migration `000044` passed. **Upgrade against a representative production-data copy has not been run.** Do not roll back after operational data is created without an approved recovery plan.

`apps/api/composer.lock` is now committed for deterministic dependency installation. The existing web `package-lock.json` is retained unchanged. A small `Suspense` boundary in the authenticated layout also fixes the baseline Next build failure caused by the existing shell's `useSearchParams`.

## Verification evidence

Environment: isolated synthetic databases on MySQL 8.4.6, PHP 8.4.24, and Node 24. No production data or external supplier communication was used.

| Check | Result |
|---|---|
| Full clean MySQL migration chain through `000044` | PASS |
| Rollback one migration and reapply `000044` | PASS |
| Changed PHP syntax and Pint formatting | PASS |
| Composer validation | PASS; existing package-license warning only |
| Iteration 27 Pest cases | **28 PASS, 0 FAIL** |
| Full Pest at baseline `e99a3a6` | **273 PASS, 42 FAIL** |
| Full Pest after Iteration 27 | **301 PASS, 42 FAIL**; same baseline failure test/file signatures |
| TypeScript `tsc --noEmit` | PASS |
| Next production build | PASS; baseline failed on the missing Suspense boundary |
| Four targeted Playwright UI flows with mocked API | **4 PASS** |
| Three UI flows against real API/MySQL | **3 PASS** |

The new backend cases are in `apps/api/tests/Feature/Iteration27PurchasingReceivingTest.php`. Coverage includes source snapshots, date validity, manual award replay/conflict, PR/tenant boundaries, legacy records, QC dimensions/partial inspection, normalized return deduplication, putaway allocation/valuation/atomic rollback, reservations, permission checks, and price masking.

Reusable Playwright assertions are in `apps/web/e2e/iteration27-flows.ts`, with CI-runner wrappers in `iteration27.spec.ts`:

1. RFQ comparison → award to PO `DRAFT`, without client quantity/price overrides.
2. Putaway draft → explicit confirmation → one post.
3. Supplier return excludes QC `PASS`; posting failure retries the same draft.
4. QC roll/non-roll ID collision isolation and saved-inspection finalize retry.

These four assertions were executed using a shared-browser CDP harness with mocked API responses, not claimed as a full Playwright-suite run. A separate synthetic-data browser run used the real API/MySQL and verified RFQ creation → quotation → PO `DRAFT`, putaway posting with two ledger entries, and supplier-return posting with one ledger entry.

### Existing full-suite failures

The 42 failing cases also fail on the unchanged baseline in the same environment. They remain unresolved, not waived. A failure-signature comparison found no new failing test locations in this iteration. Areas include:

- Actual costing/BEP, BOM, MRP, production/finishing/WIP, packing, and older commercial-fulfillment expectations.
- Authentication/RBAC, master-data APIs/import, inventory operations, and an existing receiving-UOM assertion.
- Journal/finance mapping fixtures, reporting, bank reconciliation, FX reversal, period close, and older hardening cases.

This evidence is **not** a claim that those failures are harmless or that the application is regression-green.

### Reproduction

Run only against a disposable configured MySQL test database:

```sh
cd apps/api
composer install --no-interaction
php artisan migrate --force
php vendor/bin/pest tests/Feature/Iteration27PurchasingReceivingTest.php
php vendor/bin/pest
```

Frontend:

```sh
cd apps/web
npm ci
npx tsc --noEmit
npm run build
# Start the web app in another terminal, then:
E2E_BASE_URL=http://localhost:3000 npm run test:e2e -- iteration27.spec.ts
```

The standard Playwright command requires its Chromium browser dependency. The mock UI cases do not validate a live API; backend cases and the separately executed real-API UI run provide that evidence.

## Remaining gates / production decision

**NO-GO for production.** This iteration does not close the final stabilization gate.

- Resolve the 42 baseline failures and rerun the complete supported API/static-analysis/web/Playwright pipeline.
- Run true multi-process concurrency/deadlock tests for award, numbering, QC, return, and putaway. Sequential idempotency and transaction-rollback tests passed; they are not concurrent execution evidence.
- Validate migration/legacy reconciliation on representative production data.
- Obtain purchasing/warehouse/QC UAT, approval/RBAC review, and explicit decisions for return approval and financial settlement before extending those behaviors.
- Complete accounting reconciliation, security/load review, backup/restore drill, and pilot approval under the final readiness iteration.

No work on PSO, later-iteration rework, subcontracting, carton allocation, accounting orchestration, or other unresolved business decisions is silently included.