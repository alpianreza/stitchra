# Stitchra — ERP Garment

Apparel Manufacturing Management System berbasis Laravel 13, Next.js 16, MySQL, Redis, dan MinIO.

> Status: implementasi sudah sampai Stage 10G, tetapi belum production-approved. Lihat [`PROJECT_STATUS.md`](./PROJECT_STATUS.md).

## Struktur

```text
apps/api/   Backend Laravel
apps/web/   Frontend Next.js
infra/      Docker Compose, Dockerfile, Nginx, MySQL
docs/       Blueprint, business rules, dan phase notes
```

## Jalankan dari VS Code dengan Docker

Prasyarat: Git, VS Code, Docker Desktop/Compose v2, internet pada build pertama, serta port `80`, `3000`, `3307`, `6379`, `8001`, `9000`, dan `9001`. PHP, Composer, Node, dan database lokal tidak wajib.

```bash
git clone https://github.com/alpianreza/stitchra.git
cd stitchra
code .
```

Buat environment API:

```bash
# macOS/Linux/Git Bash
cp apps/api/.env.example apps/api/.env

# PowerShell:
# Copy-Item apps/api/.env.example apps/api/.env
```

Build, start dependency services, lalu generate key:

```bash
docker compose -f infra/docker-compose.yml build
docker compose -f infra/docker-compose.yml up -d mysql redis minio
docker compose -f infra/docker-compose.yml ps
docker compose -f infra/docker-compose.yml run --rm --no-deps api php artisan key:generate
```

Tunggu MySQL, Redis, dan MinIO berstatus `healthy`, kemudian:

```bash
docker compose -f infra/docker-compose.yml up -d
docker compose -f infra/docker-compose.yml exec api php artisan db:seed --force
```

Buka:

- App/Nginx: <http://localhost>
- Frontend: <http://localhost:3000>
- API: <http://localhost:8001>
- MinIO Console: <http://localhost:9001>

Command umum:

```bash
docker compose -f infra/docker-compose.yml logs -f api web
docker compose -f infra/docker-compose.yml exec api ./vendor/bin/pest
docker compose -f infra/docker-compose.yml exec api ./vendor/bin/pint --test
docker compose -f infra/docker-compose.yml exec web npm run build
docker compose -f infra/docker-compose.yml exec web npm run test:e2e
docker compose -f infra/docker-compose.yml down
```

Build pertama masih menggunakan `composer install`/`npm install` tanpa lockfile. Dependency belum deterministik sampai `composer.lock` dan `package-lock.json` asli dibuat dan di-commit.

Jika dependency berubah:

```bash
docker compose -f infra/docker-compose.yml build --no-cache api web
docker compose -f infra/docker-compose.yml up -d --force-recreate api web nginx
```

Reset seluruh volume lokal:

```bash
docker compose -f infra/docker-compose.yml down -v
docker compose -f infra/docker-compose.yml up -d --build
```

Troubleshooting:

- Dependency hilang: recreate Compose; `/app/vendor` dan `/app/node_modules` harus menjadi volume terpisah.
- Migration gagal: lihat `docker compose -f infra/docker-compose.yml logs api` dan `docker compose -f infra/docker-compose.yml exec api php artisan migrate:status`.
- Windows lambat: simpan repo pada filesystem WSL2.

Migration `000015`–`000021` masih memerlukan clean dan representative-data smoke test sebelum production.

## Dokumentasi

- [Business rules](./docs/ERP_GARMENT_BUSINESS_RULES.md)
- [Roadmap](./docs/ERP_GARMENT_IMPLEMENTATION_ROADMAP.md)
- [Decision log](./docs/DECISION_LOG.md)
- [Project status](./PROJECT_STATUS.md)

Kode belum berarti sistem telah lolos full test, migration smoke test, UAT, security review, atau accounting sign-off.
