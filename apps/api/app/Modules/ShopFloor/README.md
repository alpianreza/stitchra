# Modul Shop Floor

## Sewing / WIP invariants (Iteration 5)

- `Bundle` remains the authoritative shop-floor input; new traced bundles must originate from a completed Lay through Cut Output.
- Historical bundles with `cut_output_id = NULL` remain readable and can continue through the legacy scan path; they are explicitly reported as incomplete lineage and are not backfilled.
- A production scan is append-only and snapshots the full bundle quantity. Partial-bundle sewing is **NOT DEFINED** by the official rules, so Iteration 5 does not invent partial allocation or reject/rework arithmetic.
- The first Sewing IN records an append-only CUTTING → SEWING WIP transfer. The final routing-operation OUT records SEWING → FINISHING.
- WIP transfer is a production transaction ledger, not a new inventory/stock movement. No stock ledger entry is created by scan/WIP traceability.
- Unique scan and transfer constraints plus bundle/MO row locks prevent double input, double output, and duplicate completion.
- QC handoff directly from Sewing, QC-input quantity equivalence, and reject/rework quantity disposition remain **NOT DEFINED**. Existing NCR/Rework behavior is unchanged.

## Finishing invariants (Iteration 6)

- A Finishing IN is accepted only for an ACTIVE Bundle whose current stage is FINISHING and which has an append-only SEWING → FINISHING WIP transfer for the same company, MO, Bundle, and full quantity.
- Finishing continues to reuse append-only `production_scans`; no duplicate Finishing transaction entity or stock movement is introduced.
- Finishing OUT requires its matching IN and snapshots the same full Bundle quantity. Partial Finishing and defect/reject/rework arithmetic are **NOT DEFINED** and are not inferred.
- Finishing operations must exist in the MO routing snapshot. After a completed Finishing operation, the next Finishing operation must move forward by routing sequence; operation-stage classification and mandatory Finishing operation set remain **NOT DEFINED**.
- Eligible Finishing WIP and full reverse lineage are exposed through the existing Shop Floor API/UI.
- Packing still requires QC FINAL PASS. Direct Bundle/Finishing Output → carton allocation is **NOT DEFINED**, so Iteration 6 exposes this boundary explicitly but does not invent a Packing transaction, carton allocation, stock movement, or historical backfill.

## Online and offline scan invariants

- Online and offline scans share the same locked state-transition engine.
- Every bundle has monotonic `scan_version`; an offline event must send `expected_bundle_version`.
- Each device event has a unique `client_event_id` and canonical SHA-256 payload hash.
- Same event and payload returns `replayed`; reused event id with a different payload returns `conflict`.
- Stale bundle versions return the current version and non-sensitive bundle snapshot.
- Device timestamps older than the configured window or too far in the future are rejected.
- Batch sync returns per-event `applied`, `replayed`, `conflict`, or `rejected` results.
- Existing ordering remains enforced: OUT requires IN, previous sewing operation requires OUT, and finishing requires all sewing OUT.

## Device security

- Device enrollment issues a time-limited Sanctum token with only `shopfloor:scan`.
- Device tokens are rejected by all permission-protected administration/business routes because they lack `api:access`.
- Devices are company/user bound, rate-limited, auditable, and explicitly revocable; revocation deletes the token.
- Plaintext token is returned only at enrollment.

## Configuration

- `SHOPFLOOR_DEVICE_TOKEN_DAYS=30`
- `SHOPFLOOR_OFFLINE_MAX_AGE_DAYS=7`
- `SHOPFLOOR_CLOCK_SKEW_MINUTES=5`
- `SHOPFLOOR_SYNC_BATCH_SIZE=100`

Runtime verification remains **DEFERRED — FINAL VERIFICATION PHASE**.
