#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# Container entrypoint
#
# Runs once when the container starts. Bootstraps the Laravel application
# (key generation if missing, cache optimisation, migrations) then hands off
# to Supervisor, which manages php-fpm, nginx, and the scheduler.
# -----------------------------------------------------------------------------
set -e

echo "[entrypoint] Starting PDF/DOCX File Storage Service..."

# ── Ensure APP_KEY is set ─────────────────────────────────────────────────────
if [ -z "${APP_KEY}" ]; then
    echo "[entrypoint] APP_KEY not set — generating a new one..."
    php /var/www/artisan key:generate --force
fi

# ── Ensure storage and cache directories are writable ────────────────────────
chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache
chmod -R 775 /var/www/storage /var/www/bootstrap/cache

# ── Ensure the uploads directory exists (volume may be freshly mounted) ───────
mkdir -p /var/www/storage/app/uploads
chown -R www-data:www-data /var/www/storage/app/uploads

# ── Run database migrations ───────────────────────────────────────────────────
echo "[entrypoint] Running database migrations..."
php /var/www/artisan migrate --force --no-interaction

# ── Optimise for production ───────────────────────────────────────────────────
echo "[entrypoint] Caching config, routes, and views..."
php /var/www/artisan config:cache  --no-interaction
php /var/www/artisan route:cache   --no-interaction
php /var/www/artisan view:cache    --no-interaction

# ── Hand off to Supervisor ────────────────────────────────────────────────────
echo "[entrypoint] Handing off to supervisord..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
