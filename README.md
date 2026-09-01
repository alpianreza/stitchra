# Stitchra — ERP Garment

Apparel Manufacturing Management System untuk proses garment end-to-end, dibangun sebagai Laravel modular monolith dengan frontend Next.js.

> **Readiness:** implementation and hardening records exist through Phase 10G, but the system is not production-approved. See the canonical [Project Status](./docs/00-governance/PROJECT_STATUS.md).

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

## Quick Start (Docker)

Prerequisites: Git, Docker Desktop or Docker Compose v2, internet on first build, and free host ports `8080`, `3000`, `8001`, `3307`, `6379`, `9000`, and `9001`. PHP, Composer, Node, and a local database are not required.

Host ports are intentionally non-default so the stack can run side by side with a local XAMPP install (Apache on `80`, MySQL on `3306`, `php.exe` on `8000`):

| Service | Host URL / port | Notes |
|---|---|---|
| Application (Nginx) | <http://localhost:8080> | routes `/api/*` to the API, everything else to the Web |
| Web (Next.js) | <http://localhost:3000> | also reachable through the Application URL |
| API (Laravel) | <http://localhost:8001> | direct; or <http://localhost:8080/api> via Nginx |
| Reverb (WebSocket) | internal only | broadcasting goes through Redis |
| MinIO Console | <http://localhost:9001> | dev credentials live in `infra/docker-compose.yml` |
| MySQL | `localhost:3307` | host port `3306` is used by XAMPP |
| Redis | `localhost:6379` | |

Build and start everything (MySQL, Redis, MinIO, API, Reverb, Web, Nginx):

```bash
git clone https://github.com/alpianreza/stitchra.git
cd stitchra
docker compose -f infra/docker-compose.yml up -d --build
```

Migrations run automatically when the API container starts (`infra/startup-api.sh`). `APP_KEY` and dev service credentials are supplied by Compose, and a one-shot `minio-init` service creates the S3 bucket. Seed the initial data once:

```bash
docker compose -f infra/docker-compose.yml exec api php artisan db:seed --force
```

Open:

- Application: <http://localhost:8080>
- Web: <http://localhost:3000>
- API: <http://localhost:8001> (or <http://localhost:8080/api>)
- MinIO Console: <http://localhost:9001>

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

## Troubleshooting

- Host port conflict on startup: on a XAMPP machine ports `80`, `3306`, and `8000` may already be taken. This stack therefore maps `8080`, `3307`, and `8001`. If another port is taken, change the host side of the mapping in `infra/docker-compose.yml` together with `FRONTEND_URL` / `SANCTUM_STATEFUL_DOMAINS`, then run `docker compose -f infra/docker-compose.yml up -d` again.
- API migration errors: `docker compose -f infra/docker-compose.yml logs api`, then `docker compose -f infra/docker-compose.yml exec api php artisan migrate:status`.
- Full local reset (destroys all volumes and data): `docker compose -f infra/docker-compose.yml down -v`.

See the [Containerization Guide](./CONTAINERIZATION.md) for the full operations guide.

## Deployment

Deployment is on-premise-first through Docker and Nginx. Before production, complete secrets, HTTPS, monitoring, backup/restore, migration, concurrency, accounting, security, and UAT requirements from the [Project Status](./docs/00-governance/PROJECT_STATUS.md).

## Documentation

Start at the [Documentation Index](./docs/README.md). It defines canonical sources, authority precedence, lifecycle status, and phase-history navigation.