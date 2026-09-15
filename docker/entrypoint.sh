#!/usr/bin/env sh
set -e

# Ensure required storage hierarchy exists on mounted volume
mkdir -p /var/www/html/storage/app/private/secure_temp
mkdir -p /var/www/html/storage/app/public
mkdir -p /var/www/html/storage/framework/cache/data
mkdir -p /var/www/html/storage/framework/sessions
mkdir -p /var/www/html/storage/framework/views
mkdir -p /var/www/html/storage/logs
mkdir -p /var/www/html/bootstrap/cache

# Fix ownership and permissions for runtime user
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Enforce strict read-only immutability for MODNet neural model directory
if [ -d "/var/www/html/storage/app/models" ]; then
    chmod -R 555 /var/www/html/storage/app/models
fi

# Optimization warming in production (skip if database or key not yet provisioned)
if [ "$APP_ENV" = "production" ] && [ -n "$APP_KEY" ]; then
    php artisan config:cache || true
    php artisan route:cache || true
    php artisan view:cache || true
fi

# NOTE: Database migrations are deliberately EXCLUDED here.
# Migrations must be run as an explicit deployment step:
# docker compose run --rm app php artisan migrate --force

exec "$@"
