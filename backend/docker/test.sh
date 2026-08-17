#!/usr/bin/env bash
# GW-System — run the backend test suite inside the Docker container.
#
# The compose environment (APP_ENV=local, DB_DATABASE=gw_system,
# QUEUE_CONNECTION=database, ...) would otherwise override phpunit.xml's
# <env> values (PHPUnit only forces when force="true"). This wrapper pins the
# test environment explicitly so the suite behaves exactly like a host run.
#
# Usage:  docker compose exec backend gw-test [phpunit args...]

set -e

cd /var/www/gw-system/backend

exec env \
  APP_ENV=testing \
  DB_DATABASE=gw_system_testing \
  QUEUE_CONNECTION=sync \
  CACHE_STORE=array \
  SESSION_DRIVER=array \
  MAIL_MAILER=array \
  php -d memory_limit=512M vendor/phpunit/phpunit/phpunit "$@"
