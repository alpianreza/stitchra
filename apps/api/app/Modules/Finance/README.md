# Finance, Tax, FX, and Actual Costing

## Period-end FX revaluation

- Foreign AR/AP outstanding is reconstructed as of calendar month-end using only payments dated on or before that date.
- Carrying rate comes from the invoice snapshot; closing rate comes from the latest company exchange rate not later than month-end.
- AR gain/loss = revalued base − carrying base. AP gain/loss uses the opposite sign.
- Each company/period has one immutable run, deterministic input hash, per-document lines, aggregate totals, and linked journals.
- Repeating the same run is idempotent. Changed exposure or rates causes a conflict instead of silent reposting.
- Explicit mappings are required for `AR_FX_REVALUE_GAIN`, `AR_FX_REVALUE_LOSS`, `AP_FX_REVALUE_GAIN`, and `AP_FX_REVALUE_LOSS`.
- Reversal posts on day one of the following open period and is unique per original journal.
- A period with foreign exposure cannot close without a matching revaluation hash.

## Tax and settlement

Tax/withholding lines are immutable. AR/AP payments snapshot settlement rates and post realized FX differences. The application never guesses tax or FX accounts.

## Still pending

Bank statement import/matching/reconciliation, formal close checklist, jurisdiction-specific tax returns/e-invoicing, and accounting/UAT sign-off.
