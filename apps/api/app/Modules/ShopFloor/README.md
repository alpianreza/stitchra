# Modul Shop Floor

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

Runtime verification remains pending deterministic lockfiles and CI.
