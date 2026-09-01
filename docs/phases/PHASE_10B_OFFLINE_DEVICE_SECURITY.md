# Phase 10B — Offline Scan & Device Security

## Implemented

- Company-bound device enrollment/list/revocation.
- Separate expiring `shopfloor:scan` token; no `api:access` privilege.
- Revocation deletes the Sanctum token and records an audit event.
- Monotonic bundle `scan_version` for optimistic offline concurrency.
- Device-scoped idempotency keys and canonical payload hashes.
- Replay-safe response and payload-reuse conflict detection.
- Client timestamp retention with maximum age and clock-skew limits.
- Per-event multi-status batch synchronization.
- Database unique constraints for device tokens and device event IDs.

## Client contract

The client queues events in order. Each event carries `client_event_id`, `expected_bundle_version`, `client_scanned_at`, bundle, operation, direction, stage, and optional line/employee. After an applied event, the client advances its local bundle version. On conflict it stops dependent events for that bundle, refreshes server state, and requires operator resolution instead of silently rewriting history.

## Pending pilot work

- Encrypted device storage and OS keystore verification in the real client.
- Remote wipe/push revocation UX.
- Network interruption and large-queue browser/device tests.
- Clock drift monitoring and operational dashboards.
- Runtime migrations/tests, penetration testing, and pilot sign-off.
