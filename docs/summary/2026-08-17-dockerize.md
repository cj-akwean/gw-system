# 2026-08-17 — Dockerize the full stack (dev-style)

**Goal:** Run the entire gw-system stack (Laravel 13 backend + Next.js frontend +
Postgres 18 + queue worker + scheduler) on a machine with Docker and **no** native
PHP / Composer / Node / Postgres. Previously the repo shipped no Docker files and the
official setup was Windows-bound (`setup.bat` / `start.bat`).

**Files created:**
- `docker-compose.yml` — 6 services: `db` (postgres:18, volume at `/var/lib/postgresql`
  per the v18 path-change gotcha), `setup` (one-shot migrate+seed), `backend`
  (`php -S 0.0.0.0:8000 -t public server.php`), `queue` (`queue:work --tries=3`),
  `scheduler` (`schedule:work`), `frontend` (node:24 dev server). Repo root bind-mounted
  at `/var/www/gw-system`; frontend at `/app`. UID/GID 1000 (`app`/`node`).
- `backend/server.php` — built-in-server router: serves existing files as-is (`return
  false`) and falls back to `public/index.php`. Without it, `php -S ... public/index.php`
  handed *every* request (css/js included) to the front controller → static assets came
  back as landing-page HTML → **admin/portal UI rendered unstyled/broken**.
- `backend/Dockerfile` — php:8.4-cli + composer:2, extensions (bcmath, gd, intl,
  mbstring, pdo_pgsql, pgsql, pcntl, zip, ...), `variables_order=EGPGS` ini in
  `conf.d/zz-gw.ini`, entrypoint/setup/test wrappers at `/usr/local/bin/gw-*`.
- `backend/docker/entrypoint.sh` — `.env` bootstrap from `.env.example`, composer install,
  key:generate, `exec "$@"`.
- `backend/docker/setup.sh` — `migrate --force` + conditional `db:seed` (only when users
  table is empty).
- `backend/docker/test.sh` (`gw-test`) — pins `APP_ENV=testing DB_DATABASE=gw_system_testing
  QUEUE_CONNECTION=sync CACHE_STORE=array SESSION_DRIVER=array MAIL_MAILER=array` before
  running `vendor/phpunit/phpunit/phpunit`.
- `backend/docker/initdb/01-create-testing-db.sql` — `CREATE DATABASE gw_system_testing;`
- `backend/.dockerignore`, `frontend/Dockerfile`, `frontend/.dockerignore`.

**Files modified:**
- `backend/database/seeders/DatabaseSeeder.php` — moved the two `User::updateOrCreate`
  calls (admin + test) to run **before** `DemoPortalDataSeeder`; previously the demo-data
  seeder ran first, so on a genuinely fresh DB it silently no-op'd (its guard looked up the
  not-yet-created test user) → no paid/overdue/unpaid invoices. Latent bug that only
  surfaces on a fresh install.
- `docs/installation-linux-macos-docker.md` — replaced "repo ships no Docker files" with
  new **Approach C** (full-stack compose) + commands + gotchas; kept Approach A as the
  lighter DB-only option. Backend command documented as `php -S ... -t public server.php`.
- `README.md` — added full-stack Docker quickstart to the non-Windows section.
- `ARCHITECTURE.md` — Database section: documented the Docker dev stack (incl. `server.php`
  router reasoning).
- `docs/insights/product-decisions.md` — §50 "Docker is the fourth first-class dev path;
  dev-style bind mounts, not a production artifact" with the container gotchas.
- `backend/server.php` (new) — see Files created.

**Bugs found & fixed (root cause):**
1. **`php artisan serve` env filter** → API 502/empty `$_ENV`. ServeCommand passes only a
   whitelist of env vars to its child `php -S`; fix = run `php -S` directly + EGPCS ini.
2. **Compose env leaks into PHPUnit** → 60 failures/10 errors on first container test run.
   phpunit.xml `<env>` isn't `force=true`, so container env (APP_ENV=local, DB_DATABASE=gw_system,
   QUEUE_CONNECTION=database, database session/cache/mail) won. Fix = `gw-test` wrapper pins
   the test env; no phpunit.xml change needed (host runs unaffected).
3. **Stale per-service images** → setup failed `cp: cannot stat '.env.example'`. Compose builds
   a distinct image per service even from one context (`gw-system-setup` vs `gw-system-backend`),
   so rebuilding only `backend` left setup/queue/scheduler on the old `/var/www/html` path.
   Fix = `docker compose build` (all).
4. **Seeder ordering** (above) → missing demo invoices on fresh DB.
5. **Static assets not served → no UI** (reported after initial "done": "backend has no
   UI"). `php -S 0.0.0.0:8000 -t public public/index.php` used `index.php` as the PHP
   built-in server's router, so EVERY request went through the Laravel front controller;
   `/css/filament/app.css` etc. returned the landing page HTML (200 but ~2KB text/html),
   leaving the Filament admin panel unstyled/broken. Fix = add `backend/server.php`
   (serves existing files via `return false`, falls back to `index.php`) and point the
   compose command at it. Verified: css 613KB `text/css`, js `application/javascript`,
   all admin assets 200.
6. (Recovered context from summary: earlier `sqlite-session` bug — sessions now in Postgres —
   and HostBackupTest path issues resolved by mounting the repo root at `/var/www/gw-system`,
   which matches `HostBackupTest::repoRoot()` = `realpath(dirname(__DIR__,3))`.)

**Test results:**
- `docker compose exec backend gw-test` → **OK (670 tests, 2879 assertions)**, ~53s. (Not the
  stale "282/282" figure in AGENTS.md.) No direct tests cover the seeders, so the seeder fix
  is verified only by re-seeding and checking invoice rows (3 = paid/overdue/unpaid) + portal
  API showing the 2 actionable ones.
- Smoke checks (after `server.php` fix): admin/login 200 with all Filament CSS/JS assets
  serving real content (css 613KB `text/css`, js `application/javascript`), /admin 302,
  frontend 200 with `localhost:8000` baked in, portal login 200, invoices/payments 200 with
  Bearer token; Postgres 18 healthy; both `gw_system` and `gw_system_testing` exist.
- Throwaway `backend/tests/Feature/DockerDiagTest.php` and `backend/public/_probe.php` were
  removed after diagnosis.

**Known gaps / next steps:**
- No automated test asserts the seeder order fix (verify manually via fresh `docker compose down
  -v && docker compose up -d` then invoice counts).
- Images are dev-style only; production containerization (Nginx+FPM+frontend build) is not done
  and is explicitly out of scope — prod remains the native deployment-runbook path.
- AGENTS.md test invocation docs still describe the host `-d memory_limit` command; the container
  equivalent is `gw-test`.
- PayMongo live/reconcile paths, email (Resend/Mailtrap), and SMS were not exercised from inside
  the container (no keys; would need `.env` fill-in).
