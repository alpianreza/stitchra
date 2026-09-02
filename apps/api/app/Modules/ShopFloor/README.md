# Modul Shop Floor

## Sewing / WIP invariants (Iteration 5)

- `Bundle` remains the authoritative shop-floor input; new traced bundles must originate from a completed Lay through Cut Output.
- Historical bundles with `cut_output_id = NULL` remain readable and can continue through the legacy scan path; they are explicitly reported as incomplete lineage and are not backfilled.
- A production scan is append-only and snapshots the full bundle quantity. Partial-bundle sewing is **NOT DEFINED** by the official rules, so Iteration 5 does not invent partial allocation or reject/rework arithmetic.
- The first Sewing IN records an append-only CUTTING → SEWING WIP transfer. The final routing-operation OUT records SEWING → FINISHING.
- WIP transfer is a production transaction ledger, not a new inventory/stock movement. No stock ledger entry is created by scan/WIP traceability.
- Unique scan and transfer constraints plus bundle/MO row locks prevent double input, double output, and duplicate completion.
- QC handoff directly from Sewing, QC-input quantity equivalence, and reject/rework quantity disposition remain **NOT DEFINED**. Existing NCR/Rework behavior is unchanged.

## Online and offline scan invariants

- Online and offline scans share the same locked state-transition engine.
- Every bundle has monotonic `scan_version`; an offline event must send `expected_bundle_version`.
- Each device event has a unique `client_event_id` and canonical SHA-256 payload hash.
- Same event and payload returns `replayed`; reused event id with a different payload returns `conflict`.
- Existing ordering remains enforced: OUT requires IN, previous sewing operation requires OUT, and finishing requires all sewing OUT.

Runtime verification remains DEFERRED — FINAL VERIFICATION PHASE.
