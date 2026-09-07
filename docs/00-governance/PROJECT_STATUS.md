---
title: Stitchra ERP Project Status
status: ACTIVE
version: 2.3
last_updated: 2026-09-07
authority: GOVERNANCE
---

# Stitchra ERP — Project Status

This is the canonical current-state document. It describes implementation evidence and readiness; it does not redefine business rules.

## Current Status

- Phase 01–09 implementation and hardening records exist on `main`.
- Phase 10A–10G records cover fabric leftover/UOM, offline device security, MO standard-cost snapshots, tax and realized FX, period-end FX revaluation, bank reconciliation, and formal period closing.
- Web UI covers the documented operational modules and has received shared design-system, application-shell, table, form, dashboard, approval, shipping, reporting, and QC modernization work.
- [Iteration 27](../ITERATION_27_PURCHASING_RECEIVING_COMPLETENESS.md) implements RFQ → quotation → manual comparison/award → PO DRAFT, operational supplier return, and same-warehouse putaway with receipt/location trace. Existing PO approval and unresolved return-finance/approval decisions are preserved.
- Iteration 27 was merged into `main` at the user's request (`a8d594b`). [Iteration 28](../ITERATION_28_PRODUCT_DEVELOPMENT_COMPLETENESS.md) adds versioned Style/Size Spec operations, private Tech Pack upload/download, sample response/version workflows, and explicit approved-sample selection at MO release.
- **Latest change verification: SOURCE REVIEW ONLY.** The user requested no tests/builds for Iteration 28. Migration `000045`, runtime/API/storage, Pest, PHP lint, TypeScript, Next build, and Playwright were not run; no passing result is claimed for this change.
- Historical Iteration 27 targeted verification passed: 28 backend cases, four mocked-API Playwright flows, three real-API/MySQL UI flows, clean migrations, migration `000044` rollback/reapply, TypeScript, and Next production build. These results do not verify Iteration 28.
- The last executed full Pest (Iteration 27) was **not green**: 301 passed / 42 failed, compared with 273 passed / the same 42 failure signatures at baseline `e99a3a6`. Current counts after Iteration 28 are unknown. Existing failures are not waived; future MO-release fixtures need the new sample prerequisite.
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

1. Keep deterministic lockfiles current: Composer lock is now committed; the existing npm lockfile is retained. Validate dependency/security policy in the supported deployment environment.
2. Run representative-data MySQL upgrade/reconciliation. The clean migration chain through `000044` and its rollback/reapply passed on isolated MySQL 8.4; production-data migration is still unverified.
3. Resolve the 42 baseline API test failures and complete the supported API, static-analysis, web, and full Playwright pipeline. Iteration 27 targeted tests, TypeScript, and Next build passed locally; this is not a global green CI claim.
4. Run real multi-process concurrency tests for numbering, inventory, scans, finance, reconciliation, and period closing.
5. Validate exact AQL tables with QA/business owners.
6. Obtain accounting sign-off for mappings, taxes, FX, statements, BEP, and period close.
7. Complete security review, production-scale query/load review, backup/restore drill, UAT, and pilot approval.
8. Decide or formally retain defaults for unresolved OBD/TD items documented in the locked business set.
9. Review and validate Iteration 28's sample gate rollout, mandatory buyer/stage policy, legacy metadata, private S3/MinIO configuration, and file-size limits before runtime/production use. No PP-only policy or historical sample selection is assumed.

## Production Decision

**NO-GO until the blockers above are completed and approved.**

## Historical Changes

Detailed implementation history is intentionally separated from current state:

- [Phase 01–09 records](../04-phases/README.md#phase-01-09)
- [Phase 10A–10G records](../04-phases/README.md#phase-10a-10g)
- [Iteration 25 — Delivery Schedule → Shipment Plan](../ITERATION_25_DELIVERY_SCHEDULE_SHIPMENT_PLAN.md)
- [Iteration 26 — Commercial Invoice, Export Documents & Container](../ITERATION_26_COMMERCIAL_EXPORT_CONTAINER.md)
- [Iteration 27 — Purchasing & Receiving Completeness](../ITERATION_27_PURCHASING_RECEIVING_COMPLETENESS.md)
- [Iteration 28 — Product Development Completeness](../ITERATION_28_PRODUCT_DEVELOPMENT_COMPLETENESS.md)
- [Decision Log](../DECISION_LOG.md)

## Related Documents

- [Documentation Index](../README.md)
- [Business Rules](../ERP_GARMENT_BUSINESS_RULES.md)
- [Permission Map](../PERMISSION_MAP.md)
- [Containerization Guide](../../CONTAINERIZATION.md)
