#!/usr/bin/env bash
# GW-System — one-shot DB setup for the `setup` compose service.
#
# Runs migrate + seed only when the database is empty (users table has no
# rows), so repeated `docker compose up` runs are no-ops after the first boot.

set -e

cd /var/www/gw-system/backend

php artisan migrate --force

if [ "$(php artisan tinker --execute='echo (int) App\Models\User::exists();' --no-interaction 2>/dev/null | tr -d '\n')" != "1" ]; then
    echo "[gw-setup] empty database — seeding demo data"
    php artisan db:seed --force
else
    echo "[gw-setup] database already seeded — skipping"
fi
