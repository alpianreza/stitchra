# Finance, Actual Costing, Tax, and FX

## Tax and withholding

- Company-scoped tax codes support output tax, input tax, withholding receivable, and withholding payable.
- Taxable base cannot exceed invoice subtotal; duplicate tax codes are rejected.
- Invoice equation: `total = subtotal + tax - withholding`.
- Tax lines snapshot code, kind, rate, base, and amount and are immutable/append-only.
- AR tax is finalized with shipment invoice creation.
- AP tax is finalized only after MATCHED + APPROVED and before any payment.
- Nonzero tax posting requires explicit `AR_TAX`, `AR_WITHHOLDING`, `AP_TAX`, or `AP_WITHHOLDING` account mappings.

## Foreign-currency settlement

- Invoice and payment each retain currency amount, exchange rate, and base amount.
- Base currency always uses rate 1.
- Foreign rate may be supplied explicitly or resolved from the latest company rate not later than the transaction date.
- Payment dates before invoice dates are rejected.
- AR realized result is `settlement base - carrying base`; AP realized result is `carrying base - settlement base`.
- Gains/losses use explicit side-specific mappings: `AR_FX_GAIN`, `AR_FX_LOSS`, `AP_FX_GAIN`, `AP_FX_LOSS`.

## MO standard cost

Actual-cost variance uses an immutable MO standard-cost snapshot rather than the newest style cost sheet.

## Still pending

Unrealized period-end FX revaluation, bank-statement reconciliation, formal close checklist, jurisdiction-specific tax returns/e-invoicing, and accounting/UAT sign-off.
