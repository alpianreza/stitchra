# Modul Finance & Costing

## GL invariants

- Journal requires at least two lines, debit XOR credit, and exact balance within 0.0001.
- Journal date must belong to the declared `YYYY-MM` period.
- Period FK is composite `(company_id, period)`; posting and closing serialize on company/period locks.
- Every COA and account mapping must belong to the same company; debit and credit mapping cannot be identical.
- Auto journal uses a unique posting key derived from company×event×source; retry with a different amount is rejected as an idempotency conflict.
- Reversal is locked and unique. Original and reversing journal remain in trial-balance arithmetic so they net to zero.

## AR/AP invariants

- One non-void AR invoice per shipped shipment, protected by a database unique key.
- Shipment/SO prices and exchange rate are validated and snapshotted.
- AR/AP payments lock invoices before computing outstanding; concurrent overpayment is rejected.
- AP payment requires MATCHED and APPROVED supplier invoice.
- Journal period follows invoice/payment date, not server execution month.
- Aging is tenant-scoped and validates the as-of date.

## Actual costing and BEP

- Output counts each bundle once only after OUT from the final routing operation; `qty_produced` is fallback.
- Material cost uses historical issue-ledger unit cost; missing historical cost fails clearly rather than using current average.
- Line and overhead rates are mandatory for the requested period.
- Subcon fees are MO/company-scoped.
- Factory BEP uses only the latest approved cost sheet per style.

Regression tests are present, but runtime/CI has not been declared green because deterministic lockfiles are still absent.
