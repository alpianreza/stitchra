#!/bin/sh
set -e

cd /app

# .env minimal (konfigurasi runtime utama disuplai docker-compose environment)
if [ ! -f .env ]; then
    touch .env
    echo "[startup] .env dibuat (kosong)"
fi

# APP_KEY wajib ada sebelum artisan yang butuh enkripsi.
# Normalnya APP_KEY sudah di-set via compose; ini jaring pengaman bila belum.
if [ -z "${APP_KEY:-}" ]; then
    echo "[startup] APP_KEY kosong, generating..."
    php artisan key:generate --force
fi

echo "[startup] migrate..."
php artisan migrate --force

php artisan storage:link >/dev/null 2>&1 || true

echo "[startup] start php-fpm + nginx"
php-fpm -D
exec nginx -g "daemon off;"