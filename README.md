# Stitchra — ERP Garment

Apparel Manufacturing Management System berbasis Laravel 13, Next.js 16, MySQL, Redis, dan MinIO.

> Status: implementasi sudah sampai Stage 10E, tetapi belum production-approved. Lihat [`PROJECT_STATUS.md`](./PROJECT_STATUS.md).

## Struktur repository

```text
apps/api/   Backend Laravel
apps/web/   Frontend Next.js
infra/      Docker Compose, Dockerfile, Nginx, MySQL
apps/api/.env.example  Contoh environment API
docs/       Blueprint, business rules, dan phase notes
```

## Cara paling cepat menjalankan dari VS Code

### Prasyarat

- Git
- VS Code
- Docker Desktop dengan Docker Compose v2
- Internet untuk mengunduh image dan dependency pada build pertama
- Port lokal `80`, `3000`, `3306`, `6379`, `8000`, `9000`, dan `9001` tersedia

PHP, Composer, Node, npm, MySQL, dan Redis lokal tidak wajib untuk cara Docker ini.

### 1. Clone dan buka repository

```bash
git clone https://github.com/alpianreza/stitchra.git
cd stitchra
code .
```

Jalankan command berikut dari terminal VS Code pada folder root `stitchra`.

### 2. Buat environment API

macOS/Linux/Git Bash:

```bash
cp apps/api/.env.example apps/api/.env
```

PowerShell:

```powershell
Copy-Item apps/api/.env.example apps/api/.env
```

Jangan commit file `.env` atau secret lokal.

### 3. Build image

```bash
docker compose -f infra/docker-compose.yml build
```

Build pertama menjalankan `composer install` dan `npm install`. Repo belum memiliki `composer.lock` dan `package-lock.json`, sehingga hasil dependency belum deterministik sampai lockfile asli dibuat dan di-commit.

### 4. Jalankan service data terlebih dahulu

```bash
docker compose -f infra/docker-compose.yml up -d mysql redis minio
```

Tunggu sampai status ketiganya `healthy`:

```bash
docker compose -f infra/docker-compose.yml ps
```

### 5. Generate application key

```bash
docker compose -f infra/docker-compose.yml run --rm --no-deps api php artisan key:generate
```

Command ini menulis `APP_KEY` ke `apps/api/.env` pada working copy.

### 6. Jalankan seluruh aplikasi

```bash
docker compose -f infra/docker-compose.yml up -d
```

API container otomatis menjalankan migration pada environment local. Setelah API sehat, jalankan seed satu kali:

```bash
docker compose -f infra/docker-compose.yml exec api php artisan db:seed --force
```

### 7. Buka aplikasi

- Aplikasi melalui Nginx: <http://localhost>
- Frontend langsung: <http://localhost:3000>
- API langsung: <http://localhost:8000>
- MinIO API: <http://localhost:9000>
- MinIO Console: <http://localhost:9001>

Lihat log jika service belum sehat:

```bash
docker compose -f infra/docker-compose.yml logs -f api web
```

## Command development sehari-hari

```bash
# Status container
docker compose -f infra/docker-compose.yml ps

# Laravel test
docker compose -f infra/docker-compose.yml exec api php artisan test

# Pest
docker compose -f infra/docker-compose.yml exec api ./vendor/bin/pest

# Laravel Pint check
docker compose -f infra/docker-compose.yml exec api ./vendor/bin/pint --test

# Next.js build
docker compose -f infra/docker-compose.yml exec web npm run build

# Playwright
docker compose -f infra/docker-compose.yml exec web npm run test:e2e

# Masuk shell API
docker compose -f infra/docker-compose.yml exec api sh

# Matikan container tanpa menghapus data
docker compose -f infra/docker-compose.yml down
```

Jika `composer.json` atau `package.json` berubah, rebuild image dan recreate container:

```bash
docker compose -f infra/docker-compose.yml build --no-cache api web
docker compose -f infra/docker-compose.yml up -d --force-recreate api web nginx
```

## Reset database lokal

Perintah berikut menghapus seluruh database, Redis, storage volume, dan dependency volume Docker lokal:

```bash
docker compose -f infra/docker-compose.yml down -v
docker compose -f infra/docker-compose.yml up -d --build
```

Setelah reset, generate ulang key bila `.env` juga dihapus, lalu jalankan seed.

## Troubleshooting

### `vendor/autoload.php` atau package frontend tidak ditemukan

Pastikan Compose terbaru memiliki anonymous volume `/app/vendor` dan `/app/node_modules`, lalu recreate:

```bash
docker compose -f infra/docker-compose.yml down
docker compose -f infra/docker-compose.yml up -d --build --force-recreate
```

### API gagal saat migration

```bash
docker compose -f infra/docker-compose.yml logs api
docker compose -f infra/docker-compose.yml exec api php artisan migrate:status
```

Migration `000015`–`000019` masih memerlukan clean dan representative-data smoke test sebelum deployment production.

### Port sudah digunakan

Hentikan service yang memakai port terkait atau ubah mapping sisi kiri pada `infra/docker-compose.yml`.

### Windows

Simpan repository pada filesystem WSL2 bila bind-mount Docker Desktop terasa lambat. Jalankan command dari terminal WSL/Git Bash atau gunakan padanan PowerShell untuk operasi file.

## Dokumentasi

- Business rules: [`docs/ERP_GARMENT_BUSINESS_RULES.md`](./docs/ERP_GARMENT_BUSINESS_RULES.md)
- Implementation roadmap: [`docs/ERP_GARMENT_IMPLEMENTATION_ROADMAP.md`](./docs/ERP_GARMENT_IMPLEMENTATION_ROADMAP.md)
- Decision log: [`docs/DECISION_LOG.md`](./docs/DECISION_LOG.md)
- Status audit: [`PROJECT_STATUS.md`](./PROJECT_STATUS.md)

Kode yang tersedia belum berarti sistem telah lolos full test, migration smoke test, UAT, security review, atau accounting sign-off.
