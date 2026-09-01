# Stitchra — ERP Garment

Apparel Manufacturing Management System untuk proses garment end-to-end, dibangun sebagai Laravel modular monolith dengan frontend Next.js.

> **Readiness:** implementation and hardening records exist through Stage 10G, but the system is not production-approved. See the canonical [Project Status](./docs/00-governance/PROJECT_STATUS.md).

## Architecture Summary

- Backend: Laravel 13 under `apps/api`
- Frontend: Next.js 16 / React under `apps/web`
- Data: MySQL 8.x, Redis, and MinIO
- Runtime: Docker Compose and Nginx under `infra`
- Direction: on-premise first, cloud-ready, with documented PostgreSQL portability constraints

Detailed business and architecture authority is indexed in [`docs/README.md`](./docs/README.md).

## Repository Structure

```text
apps/api/       Laravel API and modular business domains
apps/web/       Next.js web application
infra/          Docker Compose, Dockerfiles, Nginx, and service configuration
docs/           Business authority, architecture, governance, roadmap, and phase history
```

## Quick Start

Prerequisites: Git, Docker Desktop or Docker Compose v2, and available local service ports.

```bash
git clone https://github.com/alpianreza/stitchra.git
cd stitchra
cp apps/api/.env.example apps/api/.env
docker compose -f infra/docker-compose.yml build
docker compose -f infra/docker-compose.yml up -d mysql redis minio
docker compose -f infra/docker-compose.yml run --rm --no-deps api php artisan key:generate
docker compose -f infra/docker-compose.yml up -d
docker compose -f infra/docker-compose.yml exec api php artisan db:seed --force
```

Open:

- Application: <http://localhost>
- Web: <http://localhost:3000>
- API: <http://localhost:8000>
- MinIO Console: <http://localhost:9001>

See [Containerization Guide](./CONTAINERIZATION.md) for operations and troubleshooting.

## Development and Testing

```bash
docker compose -f infra/docker-compose.yml logs -f api web
docker compose -f infra/docker-compose.yml exec api ./vendor/bin/pest
docker compose -f infra/docker-compose.yml exec api ./vendor/bin/pint --test
docker compose -f infra/docker-compose.yml exec web npm run build
docker compose -f infra/docker-compose.yml exec web npm run test:e2e
docker compose -f infra/docker-compose.yml down
```

These commands describe the intended workflow; they are not evidence that tests passed. Current verification blockers and lockfile status are maintained only in the [canonical Project Status](./docs/00-governance/PROJECT_STATUS.md).

## Deployment

Deployment is on-premise-first through Docker and Nginx. Before production, complete secrets, HTTPS, monitoring, backup/restore, migration, concurrency, accounting, security, and UAT requirements from the [Project Status](./docs/00-governance/PROJECT_STATUS.md).

## Documentation

Start at the [Documentation Index](./docs/README.md). It defines canonical sources, authority precedence, lifecycle status, and phase-history navigation.
