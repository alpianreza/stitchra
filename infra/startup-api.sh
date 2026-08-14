#!/bin/sh
set -e

# Run migrations and seeders if needed
if [ "$APP_ENV" != "production" ]; then
    php artisan migrate --force
fi

# Start PHP-FPM in background
php-fpm -D

# Start Nginx in foreground
exec nginx -g "daemon off;"
