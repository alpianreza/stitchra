# Phase 10G — Formal Period Closing

## Workflow

1. Prepare deterministic checklist and manual attestations.
2. Resolve every failed system check.
3. Re-prepare until status is `READY`.
4. A different user approves the unchanged snapshot.
5. Close recomputes the snapshot and closes the GL period only if its hash still matches.

## System checks

- Journal debit/credit balance.
- No pending or unmatched AP documents through period end.
- Every active bank account has a reconciled statement covering the period.
- No overlapping imported/unapproved bank statement.
- Foreign exposure has the exact current FX revaluation hash.
- Prior-period FX journals have been reversed.
- Backup and tax review are explicitly attested.

## Controls

One run per company/period, maker-checker separation, immutable approved snapshot, audited transitions, and no public direct-close route.

## Deployment caveat

Migration `000021` and the complete close workflow need MySQL, role, concurrency, accounting, and UAT tests. No runtime pass is claimed here.
