# Stitchra — ERP Garment

Apparel Manufacturing Management System berbasis Laravel 13, Next.js 16, MySQL, Redis, dan MinIO.

> Status: implementasi sudah sampai Stage 10F, tetapi belum production-approved. Lihat [`PROJECT_STATUS.md`](./PROJECT_STATUS.md).

## Struktur

```text
apps/api/   Backend Laravel
apps/web/   Frontend Next.js
infra/      Docker Compose, Dockerfile, Nginx, MySQL
docs/       Blueprint, business rules, dan phase notes
```

## Jalankan dari VS Code dengan Docker

### Prasyarat

Git, VS Code, Docker Desktop/Compose v2, internet pada build pertama, serta port `80`, `3000`, `3306`, `6379`, `8000`, `9000`, dan `9001` yang tersedia. PHP, Composer, Node, dan database lokal tidak wajib.

### 1. Clone dan buka

```bash
git clone https://github.com/alpianreza/stitchra.git
cd stitchra
code .
```

### 2. Buat environment API

macOS/Linux/Git Bash:

```bash
cp apps/api/.env.example apps/api/.env
```

PowerShell:

```powershell
Copy-Item apps/api/.env.example apps/api/.env
```

### 3. Build image

```bash
docker compose -f infra/docker-compose.yml build
```

Build pertama menjalankan `composer install` dan `npm install`. Repo belum memiliki `composer.lock` dan `package-lock.json`, sehingga dependency belum deterministik sampai lockfile asli dibuat dan di-commit.

### 4. Start data services dan generate key

```bash
docker compose -f infra/docker-compose.yml up -d mysql redis minio
docker compose -f infra/docker-compose.yml ps
docker compose -f infra/docker-compose.yml run --rm --no-deps api php artisan key:generate
```

Tunggu MySQL, Redis, dan MinIO berstatus `healthy` sebelum generate key.

### 5. Start aplikasi dan seed

```bash
docker compose -f infra/docker-compose.yml up -d
docker compose -f infra/docker-compose.yml exec api php artisan db:seed --force
```

API container otomatis menjalankan migration pada environment local.

### 6. Buka

- Nginx/app: <http://localhost>
- Frontend: <http://localhost:3000>
- API: <http://localhost:8000>
- MinIO Console: <http://localhost:9001>

```bash
# Log
docker compose -f infra/docker-compose.yml logs -f api web

# Status
docker compose -f infra/docker-compose.yml ps

# Pest
docker compose -f infra/docker-compose.yml exec api ./vendor/bin/pest

# Pint
docker compose -f infra/docker-compose.yml exec api ./vendor/bin/pint --test

# Next build
docker compose -f infra/docker-compose.yml exec web npm run build

# Playwright
docker compose -f infra/docker-compose.yml exec web npm run test:e2e

# Stop tanpa hapus data
docker compose -f infra/docker-compose.yml down
```

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

## Troubleshooting

- `vendor/autoload.php`/package frontend hilang: recreate Compose terbaru; `/app/vendor` dan `/app/node_modules` harus menjadi volume terpisah.
- Migration gagal: jalankan `docker compose -f infra/docker-compose.yml logs api` dan `docker compose -f infra/docker-compose.yml exec api php artisan migrate:status`.
- Port bentrok: hentikan service terkait atau ubah port sisi kiri di `infra/docker-compose.yml`.
- Windows lambat: simpan repo pada filesystem WSL2 dan jalankan dari terminal WSL.

Migration `000015`–`000020` masih memerlukan clean dan representative-data smoke test sebelum production.

## Dokumentasi

- [Business rules](./docs/ERP_GARMENT_BUSINESS_RULES.md)
- [Roadmap](./docs/ERP_GARMENT_IMPLEMENTATION_ROADMAP.md)
- [Decision log](./docs/DECISION_LOG.md)
- [Project status](./PROJECT_STATUS.md)

Kode belum berarti sistem telah lolos full test, migration smoke test, UAT, security review, atau accounting sign-off.
