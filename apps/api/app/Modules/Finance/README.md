# Finance

## Bank reconciliation

- Company bank accounts bind a currency and bank GL account.
- JSON statement import validates date range, direction, positive amounts, row limit, and opening + credits − debits = closing.
- Canonical SHA-256 fingerprints reject duplicate bank transactions across imports.
- AR receipts match CREDIT lines; AP payments match DEBIT lines; bank/payment currencies must match.
- Partial and many-to-many matching are supported with ceilings on both statement lines and source payments.
- Unmatched lines may be ignored only with an explicit audited reason.
- Bank-fee lines post through the explicit `BANK_FEE` account mapping.
- Reconciliation approval requires every line to be MATCHED or IGNORED and then locks further service-level changes.

## FX and tax

Stages 10C–10E provide immutable MO standard cost, tax/withholding snapshots, realized FX settlement, month-end revaluation, close gate, and next-period reversal.

## Still pending

Formal close checklist, country-specific tax filing/e-invoicing, accounting sign-off, and runtime/UAT verification.
