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
# Build and start all services (mysql, redis, minio, api, reverb, web, nginx)
docker compose -f infra/docker-compose.yml up -d --build

# Seed data awal (sekali)
docker exec stitchra-api php artisan db:seed --force
```

Migrasi berjalan otomatis saat container api start (`php artisan migrate --force`).
`APP_KEY` dev sudah di-set di compose dan dipakai bersama oleh api & reverb (signature websocket harus cocok).

## Services & Port (host)

| Service | URL / Port host | Catatan |
|---|---|---|
| Nginx reverse proxy | http://localhost:8080 | `/api/*` -> api, sisanya -> web |
| API (Laravel) | http://localhost:8001 | akses langsung (8000 di host ini terpakai proses lain) |
| Web (Next.js) | http://localhost:3000 | juga lewat http://localhost:8080 |
| MinIO Console | http://localhost:9001 | user `stitchra` / pass `stitchra_secret` |
| MySQL | localhost:3307 | 3306 dipakai MySQL XAMPP di host ini |
| Redis | localhost:6379 | |

Bucket S3 `stitchra` dibuat otomatis oleh service one-shot `minio-init`.

## Architecture

### Multi-stage Builds
- **Dockerfile.api**: composer deps + vendor di builder; runtime PHP-FPM + Nginx dalam satu container; healthcheck `/up` (route health framework Laravel)
- **Dockerfile.web**: build Next.js di builder; runtime memakai `output: standalone` (`node server.js`), non-root user, dumb-init untuk signal handling

### Layer Caching
- Dependency (composer/npm) di-layer terpisah dari source
- `.dockerignore` di root repo membuang `.git`, `docs/`, `node_modules`, `vendor`, `.next`, artefak lain dari build context

### Health Checks
- Service inti punya readiness probe; compose `depends_on` memakai `condition: service_healthy` (mysql, redis)
- `api`: `curl http://localhost/up`; `web`: HTTP 200 di `/`

### Networking
- Semua service di jaringan `stitchra` bridge; DNS internal `mysql:3306`, `redis:6379`, `minio:9000`
- Nginx meroute `/api/*` ke api, `/` ke web
- Browser memanggil API langsung di `http://localhost:8001` via `NEXT_PUBLIC_API_URL` (build arg web)

## Development Tips

### Rebuild setelah ubah kode
Kode dibake ke image (tanpa bind mount source agar vendor/node_modules hasil build tidak tertimpa):
```bash
docker compose -f infra/docker-compose.yml build api web
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

### Logs & akses container
```bash
docker compose -f infra/docker-compose.yml logs -f api
docker compose -f infra/docker-compose.yml logs -f web
docker exec -it stitchra-api sh
docker exec -it stitchra-web sh
```

### Reset penuh (HAPUS semua data!)
```bash
docker compose -f infra/docker-compose.yml down -v
```

## Production Requirements

- Ganti semua secret dev di `infra/docker-compose.yml` (`APP_KEY`, MySQL, MinIO, Reverb) - pakai secret management (Docker Secrets, env files CI/CD)
- `APP_DEBUG=false`, `APP_ENV=production`, `TELESCOPE_ENABLED=false`
- Set domain asli di `SANCTUM_STATEFUL_DOMAINS`, `FRONTEND_URL`, dan build arg `NEXT_PUBLIC_API_URL`
- Enable HTTPS via reverse proxy / managed certificate
- Tambah resource limits per service di compose
- `spatie/browsershot` butuh Chromium di runtime bila fitur PDF/ekspor dipakai (belum termasuk di image)
- Laravel Horizon belum punya service sendiri; bila dibutuhkan jalankan `php artisan horizon` di container terpisah (ext `pcntl`/`posix` sudah terpasang di image api)

## Related Documents

- [Documentation Index](./docs/README.md)
- [Project Status](./docs/00-governance/PROJECT_STATUS.md)
- [Database Blueprint](./docs/ERP_GARMENT_DATABASE_BLUEPRINT.md)
- [Decision Log](./docs/DECISION_LOG.md)
