---
title: Stitchra ERP Project Status
status: ACTIVE
version: 2.1
last_updated: 2026-09-01
authority: GOVERNANCE
---

# Stitchra ERP — Project Status

This is the canonical current-state document. It describes implementation evidence and readiness; it does not redefine business rules.

## Current Status

- Phase 01–09 implementation and hardening records exist on `main`.
- Phase 10A–10G records cover fabric leftover/UOM, offline device security, MO standard-cost snapshots, tax and realized FX, period-end FX revaluation, bank reconciliation, and formal period closing.
- Web UI covers the documented operational modules and has received shared design-system, application-shell, table, form, dashboard, approval, shipping, reporting, and QC modernization work.
- The repository is **not production-approved**. Existing phase documents consistently retain deployment, runtime, concurrency, accounting, security, AQL, and UAT caveats.

## Current Architecture

| Layer | Current repository direction |
|---|---|
| Backend | Laravel 13 / PHP, modular monolith under `apps/api` |
| Frontend | Next.js 16 / React under `apps/web` |
| Database | MySQL 8.x on-premise, with documented PostgreSQL portability constraints |
| Cache / queue | Redis; Horizon is part of the approved stack |
| Storage | S3-compatible storage through MinIO |
| Edge | Nginx reverse proxy |
| Runtime | Docker Compose under `infra` |
| CI | GitHub Actions with API and web build jobs |

Architecture authority remains in [Module Map](../ERP_GARMENT_MODULE_MAP.md), [Database Blueprint](../ERP_GARMENT_DATABASE_BLUEPRINT.md), and [Decision Log](../DECISION_LOG.md).

## Configuration Required

Before production use, configure and validate:

1. Approval flows for each required document type.
2. Finance account mappings for automatic journals.
3. Company chart of accounts.
4. Period overhead and line cost rates.
5. Buyer-specific AQL configuration.
6. Production credentials, domains, HTTPS, secret management, storage policy, monitoring, backup, and restore procedures.

## Open Items / Production Blockers

1. Generate and commit deterministic Composer and npm lockfiles if still absent.
2. Run clean and representative-data MySQL migrations, especially migrations `000015`–`000021`.
3. Run the full API, static analysis, web build, and Playwright suites in a supported environment.
4. Run real multi-process concurrency tests for numbering, inventory, scans, finance, reconciliation, and period closing.
5. Validate exact AQL tables with QA/business owners.
6. Obtain accounting sign-off for mappings, taxes, FX, statements, BEP, and period close.
7. Complete security review, production-scale query/load review, backup/restore drill, UAT, and pilot approval.
8. Decide or formally retain defaults for unresolved OBD/TD items documented in the locked business set.

## Production Decision

**NO-GO until the blockers above are completed and approved.**

## Historical Changes

Detailed implementation history is intentionally separated from current state:

- [Phase 01–09 records](../04-phases/README.md#phase-01-09)
- [Phase 10A–10G records](../04-phases/README.md#phase-10a-10g)
- [Decision Log](../DECISION_LOG.md)

## Related Documents

- [Documentation Index](../README.md)
- [Business Rules](../ERP_GARMENT_BUSINESS_RULES.md)
- [Permission Map](../PERMISSION_MAP.md)
- [Containerization Guide](../../CONTAINERIZATION.md)
