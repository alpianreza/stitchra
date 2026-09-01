# Finance

## Formal period closing

- Closing uses `prepare → approve → close`; the former direct close API route is removed.
- Deterministic checklist snapshots cover journal balance, unresolved AP, bank reconciliation, current FX revaluation, prior FX reversal, backup verification, and tax review.
- Snapshot hash is recomputed before approval and close. Changed accounting data makes the checklist stale and requires preparation again.
- Maker and approver must be different users.
- Approved checklist inputs are immutable and every lifecycle action is audited.

## Bank, FX, tax, and costing

- Bank statements support deduplicated import, partial matching, fees, ignored reasons, and approval locking.
- Foreign invoices support realized settlement FX and period-end revaluation/reversal.
- Tax and withholding lines are immutable snapshots.
- MO variance uses an immutable standard-cost snapshot.

## Still pending

Country-specific tax filing/e-invoicing and runtime, accounting, security, and UAT verification.
