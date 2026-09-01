---
title: Stitchra Containerization Guide
status: ACTIVE
version: 1.1
last_updated: 2026-09-01
authority: OPERATIONS
---

# Stitchra Containerization Guide

For production readiness and dependency-lock status, use the canonical [Project Status](./docs/00-governance/PROJECT_STATUS.md). This guide describes intended Docker operations and does not claim that migrations or tests have passed.

## Quick Start

```bash
docker compose -f infra/docker-compose.yml up -d --build
docker compose -f infra/docker-compose.yml exec api php artisan key:generate
docker compose -f infra/docker-compose.yml exec api php artisan migrate --seed
```

## Services

- API: <http://localhost:8000> or <http://localhost/api>
- Web: <http://localhost:3000> or <http://localhost>
- MinIO Console: <http://localhost:9001>
- MySQL: `localhost:3306`
- Redis: `localhost:6379`

Credentials and secrets must come from environment configuration; this document intentionally does not publish defaults.

## Architecture

- Multi-stage Dockerfiles for API and web.
- Dependencies are built separately from application code for layer caching.
- Next.js runs as a non-root user where configured by the current Dockerfile.
- Service readiness is managed through Compose health checks and dependencies.
- Services communicate on the Compose network using service DNS names.
- Nginx routes application and API traffic according to `infra` configuration.

Do not infer dependency reproducibility from this guide. Verify lockfiles and current blockers in [Project Status](./docs/00-governance/PROJECT_STATUS.md).

## Common Operations

```bash
# Build and start
docker compose -f infra/docker-compose.yml build
docker compose -f infra/docker-compose.yml up -d

# Inspect status and logs
docker compose -f infra/docker-compose.yml ps
docker compose -f infra/docker-compose.yml logs -f api web

# Run documented checks
docker compose -f infra/docker-compose.yml exec api ./vendor/bin/pest
docker compose -f infra/docker-compose.yml exec api ./vendor/bin/pint --test
docker compose -f infra/docker-compose.yml exec web npm run build

# Stop
docker compose -f infra/docker-compose.yml down
```

Resetting volumes is destructive and should only be used for an intentional local reset:

```bash
docker compose -f infra/docker-compose.yml down -v
docker compose -f infra/docker-compose.yml up -d --build
```

## Production Requirements

- Disable debug tooling in production.
- Use external secret management and strong credentials.
- Configure real domains, HTTPS, trusted hosts, and security headers.
- Configure production logging, monitoring, and alert routing.
- Define resource limits and capacity expectations.
- Validate object-storage access and malware/file policies.
- Complete clean migration, backup/restore, security, accounting, AQL, concurrency, and UAT evidence listed in Project Status.

## Related Documents

- [Documentation Index](./docs/README.md)
- [Project Status](./docs/00-governance/PROJECT_STATUS.md)
- [Database Blueprint](./docs/ERP_GARMENT_DATABASE_BLUEPRINT.md)
- [Decision Log](./docs/DECISION_LOG.md)
