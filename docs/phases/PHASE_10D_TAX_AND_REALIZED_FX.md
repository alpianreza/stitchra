# Stage 10D — Tax, Withholding, and Realized FX

## Implemented

- Company-scoped tax-code configuration endpoints.
- Immutable AR/AP tax-line snapshots.
- Jurisdiction-neutral invoice tax and withholding calculation.
- AP finance finalization gate after three-way match and approval.
- Invoice-rate and payment-rate snapshots in transaction and base currencies.
- Dated exchange-rate lookup and base-currency rate enforcement.
- Realized FX gain/loss posting for both AR and AP settlements.
- Explicit account mappings; the application never guesses tax or FX accounts.
- Backward-compatible one-time AP finance snapshot for legacy invoices.

## Accounting identities

- Invoice total = subtotal + tax − withholding.
- AR realized FX = payment base − carrying base.
- AP realized FX = carrying base − payment base.

## Deployment caveats

Migration `000018` adds tables, columns, checks, foreign keys, and historical payment backfill. It requires clean and representative-data migration testing. Configure every nonzero tax/FX event mapping before use.

## Deferred

Unrealized month-end revaluation, reversal in the following period, bank reconciliation, country-specific filing/e-invoicing, and accounting approval remain separate stages.
