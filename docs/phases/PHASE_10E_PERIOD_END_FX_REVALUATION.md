# Phase 10E — Period-end FX Revaluation

## Implemented

- Historical month-end AR/AP foreign outstanding reconstruction.
- Invoice carrying-rate versus dated closing-rate calculation.
- Immutable run and per-document snapshots with SHA-256 input hash.
- Four explicit revaluation posting events for AR/AP gains/losses.
- One run per company and period with retry conflict detection.
- Period close blocked when FX exposure lacks a matching run.
- Unique automatic reversal into day one of the following open period.

## Concurrency model

The GL period row serializes revaluation and closing. Exposure is read without inverse invoice locks to avoid payment/closing lock-order deadlocks. A payment racing after revaluation changes the exposure hash; closing is then rejected until Finance resolves the run. A payment racing with an already-closed period cannot post and rolls back atomically.

## Deployment caveat

Migration `000019` and cross-period reversal require clean/representative MySQL migration tests and real concurrent payment-versus-close tests. No runtime pass is claimed in the restricted environment.
