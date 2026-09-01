# Modul Finance, Actual Costing, and BEP

## MO standard cost snapshot

- MO captures the approved cost sheet matching its exact BOM and routing versions.
- Snapshot stores component costs, FOB, margin, source document/version, SHA-256 hash, and timestamp.
- Once hashed, snapshot fields are immutable while normal MO status/quantity updates remain allowed.
- MO release requires an exact approved snapshot; unrelease retains it.
- Legacy/manual MOs attach one approved snapshot at first actual-cost computation and then remain stable.
- Actual variance reads the MO snapshot, never the latest mutable style cost sheet.
- Material, labor, overhead, subcon, other, total, and snapshot identity are returned in variance output.

## Remaining finance scope

Tax/withholding, FX revaluation, bank reconciliation, formal close checklist, and accounting/UAT sign-off remain pending. Runtime verification remains blocked by lockfiles and CI.
