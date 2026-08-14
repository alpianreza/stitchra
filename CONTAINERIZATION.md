# Stitchra Containerization Guide

## Quick Start

```bash
# Build and start all services
docker compose -f infra/docker-compose.yml up -d --build

# Initialize Laravel
docker exec stitchra-api php artisan key:generate
docker exec stitchra-api php artisan migrate --seed

# Create MinIO bucket
docker exec stitchra-minio mc mb minio/stitchra
```

## Services

- **API (Laravel)** — http://localhost:8000 or http://localhost/api
- **Web (Next.js)** — http://localhost:3000 or http://localhost
- **MinIO Console** — http://localhost:9001 (user: stitchra, pass: stitchra_secret)
- **MySQL** — localhost:3306
- **Redis** — localhost:6379

## Architecture

### Multi-stage Builds
- **Dockerfile.api**: Composer deps compiled in builder, lightweight runtime with PHP-FPM + Nginx
- **Dockerfile.web**: Node modules in builder, production runtime with dumb-init for signals

### Layer Caching
- Dependencies pinned via `package-lock.json` and `composer.lock`
- Code copied separately to maximize cache hits
- Non-root user for Next.js (nextjs:1001)

### Health Checks
- All services have readiness probes
- Compose service dependencies configured with health conditions
- API and Web include HTTP endpoint checks

### Networking
- All services on `stitchra` bridge network
- Internal DNS: `service_name:port` (e.g., `mysql:3306`)
- Nginx reverse proxy routes `/api/*` to API, `/` to Web

## Development Tips

### Rebuild after code changes
```bash
docker compose -f infra/docker-compose.yml build
docker compose -f infra/docker-compose.yml up -d
```

### View logs
```bash
docker compose -f infra/docker-compose.yml logs -f api
docker compose -f infra/docker-compose.yml logs -f web
```

### Access containers
```bash
docker exec -it stitchra-api sh
docker exec -it stitchra-web sh
```

### Reset all (careful!)
```bash
docker compose -f infra/docker-compose.yml down -v
docker compose -f infra/docker-compose.yml up -d --build
```

## Production Considerations

- Replace `APP_DEBUG=true` and `TELESCOPE_ENABLED=false` with appropriate values
- Use strong passwords for MySQL and MinIO
- Set `NODE_ENV` to `production` in web service (already done)
- Use external secret management (Docker Secrets, env files, or CI/CD secrets)
- Configure real domain in `SANCTUM_STATEFUL_DOMAINS`
- Set up proper logging (ELK, Datadog, etc.)
- Enable HTTPS via reverse proxy or managed certificate service
- Add resource limits: `cpu_shares`, `mem_limit` in compose for each service
