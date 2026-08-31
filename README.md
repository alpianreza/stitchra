# Stitchra — ERP Garment

Apparel Manufacturing Management System. Dibangun berdasarkan blueprint bisnis di [`docs/`](./docs) — **baca blueprint dulu sebelum coding**.

## Prinsip
> Kode mengikuti business specification, bukan sebaliknya. Semua business rule: `docs/ERP_GARMENT_BUSINESS_RULES.md`. Semua keputusan: `docs/DECISION_LOG.md`.

## Stack (DEC-2026-08-13-02/03)
- **Backend:** Laravel 13 + PHP 8.5 (modular monolith, modul = Module Map)
- **Frontend:** Next.js 16 + React (SPA via Sanctum)
- **DB:** MySQL 8.x (sementara) → portabel ke PostgreSQL (aturan: Database Blueprint §7)
- **Cache/Queue:** Redis 8 + Horizon · **Realtime:** Reverb · **Storage:** S3-compatible (MinIO lokal)
- **Infra:** Docker on-premise + Nginx · **CI/CD:** GitHub Actions
- **Test:** Pest + PHPUnit + Playwright

## Struktur
```
apps/api    → backend Laravel (modul per domain)
apps/web    → frontend Next.js
infra/      → docker-compose, nginx, Dockerfiles
docs/       → blueprint bisnis (LOCKED v1.x)
```

## Quickstart (dev)
```bash
cp apps/api/.env.example apps/api/.env
docker compose -f infra/docker-compose.yml up -d --build
docker exec stitchra-api composer install
docker exec stitchra-api php artisan key:generate
docker exec stitchra-api php artisan migrate --seed
```
Web: http://localhost · API: http://localhost:8000 · MinIO console: http://localhost:9001

## Status implementasi

Kode dan feature test sudah mencakup beberapa domain lintas fase. Keberadaan kode belum berarti fase telah lolos review/UAT. Status aktual, pekerjaan hardening, dan exit criteria production dicatat di [`PROJECT_STATUS.md`](./PROJECT_STATUS.md).

Roadmap resmi tetap tersedia di [`docs/ERP_GARMENT_IMPLEMENTATION_ROADMAP.md`](./docs/ERP_GARMENT_IMPLEMENTATION_ROADMAP.md).
