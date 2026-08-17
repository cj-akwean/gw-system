#!/usr/bin/env bash
# GW-System — backend container entrypoint.
#
# Runs once per container start, BEFORE the service command:
#   1. Bootstrap backend/.env from .env.example when missing.
#   2. composer install when vendor/ is missing (first boot).
#   3. Generate APP_KEY when .env has none.
#   4. exec the service command (serve / queue:work / schedule:work).
#
# Database setup (migrations + seed) is NOT here — it runs once in the
# dedicated `setup` compose service so concurrent service starts can never
# race a migration.

set -e

cd /var/www/gw-system/backend

if [ ! -f .env ]; then
    echo "[gw-entrypoint] creating .env from .env.example"
    cp .env.example .env
fi

if [ ! -d vendor ]; then
    echo "[gw-entrypoint] running composer install (first boot)"
    composer install --no-interaction --prefer-dist
fi

if ! grep -q '^APP_KEY=base64:' .env; then
    echo "[gw-entrypoint] generating APP_KEY"
    php artisan key:generate --force
fi

echo "[gw-entrypoint] starting: $*"
exec "$@"
