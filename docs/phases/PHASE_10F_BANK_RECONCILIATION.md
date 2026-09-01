# Stage 10F — Bank Reconciliation

## Implemented

- Company-scoped bank-account master tied to COA and optional currency.
- Balanced statement import with 5,000-row request ceiling.
- Statement/content and transaction-level SHA-256 deduplication.
- AR CREDIT and AP DEBIT payment matching.
- Partial and many-to-many matching with line/payment ceilings.
- Audited ignore reason for legitimate non-ledger lines.
- Explicit `BANK_FEE` journal posting and match.
- Approval gate requiring every line resolved, followed by reconciliation lock.

## Current import contract

The API accepts normalized JSON rows. CSV/OFX/MT940 adapters can be added later as parsers feeding the same normalized service; they must not bypass fingerprinting or balance validation.

## Deployment caveat

Migration `000020` requires clean/representative MySQL tests. Matching concurrency requires real multiprocess verification. Runtime pass is not claimed in the restricted environment.
