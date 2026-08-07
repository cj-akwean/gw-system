# GW-System — Deployment Runbook (Infra phase)

Fresh Linux VPS (Ubuntu 24.04 LTS assumed; native Postgres, no Docker — matching dev).
This is the **apply-at-go-live** document. All app-side code ships in the repo
(scheduler wiring, worker command, supervisor/cron/backup artifacts); this runbook is
the mechanical sequence to go from a blank VM to a serving, billing, backing-up system.

> Decisions still open before you can start: **hosting provider / VM size**, **domain**,
> **mail sending domain** (Resend), and **PayMongo live keys**. Everything below uses
> placeholders for those.

## 0. Order of operations (why)

Worker before cron, cron before go-live tests, TLS + webhook before real payments:

1. System + PHP + Postgres  4. App code + caches      7. Firewall + hardening
2. Postgres DB + user       5. Queue worker (supervisor)   8. Go-live smoke
3. Web server (nginx/TLS)   6. Scheduler + backups    9. Release ritual (every deploy)

## 1. System packages

```bash
sudo add-apt-repository ppa:ondrej/php -y && sudo apt update
sudo apt install -y php8.5-fpm php8.5-cli php8.5-pgsql php8.5-mbstring php8.5-xml \
    php8.5-curl php8.5-zip php8.5-gd php8.5-intl composer nginx postgresql postgresql-client \
    supervisor certbot python3-certbot-nginx ufw
```

**memory_limit — the deployment landmine.** dompdf (invoice PDFs) OOMs at 128M (we hit
this in dev: font parsing). Set `memory_limit = 512M` in BOTH `php.ini` files
(`/etc/php/8.5/fpm/php.ini` for web and `/etc/php/8.5/cli/php.ini` for the queue
worker — the worker renders PDFs too). The schedule/worker will silently fail receipts
at 128M otherwise.

## 2. Postgres

```bash
sudo -u postgres psql -c "CREATE USER gw_user WITH PASSWORD 'CHANGE_ME';"
sudo -u postgres psql -c "CREATE DATABASE gw_system OWNER gw_user;"
```

## 3. Application

```bash
sudo mkdir -p /var/www && cd /var/www
sudo git clone <repo-url> gw-system && cd gw-system/backend

sudo cp .env.example .env && sudo chmod 640 .env
# edit .env: APP_ENV=production, APP_DEBUG=false, APP_URL=https://<domain>,
# DB_*  (Postgres above), MAIL_MAILER=resend + RESEND_API_KEY,
# PAYMONGO_LIVEMODE=true + live keys + PAYMONGO_WEBHOOK_SECRET,
# QUEUE_CONNECTION=database (default anyway)

composer install --no-dev --optimize-autoloader
sudo php artisan key:generate
sudo php artisan migrate --force
sudo php artisan db:seed            # seeds admin@gwsystem.com / admin123 + barangays/rates
sudo php artisan config:cache && sudo php artisan route:cache && sudo php artisan view:cache
sudo chown -R www-data:www-data storage bootstrap/cache
sudo php artisan storage:link       # (only if public storage is ever used)
```

## 4. Queue worker (supervisor)

```bash
sudo apt install -y supervisor                       # step 1 if not already
sudo cp deploy/linux/supervisor-gw-worker.conf /etc/supervisor/conf.d/gw-worker.conf
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl status gw-system-worker*          # expect RUNNING
```

Verify honestly: queue a probe and watch it drain.

```bash
cd /var/www/gw-system/backend
php artisan queue:restart                            # safety after any deploy
sudo supervisorctl status gw-system-worker*
psql -h 127.0.0.1 -U gw_user -d gw_system -c "select count(*) from jobs;"   # ~0 at rest
```

The worker flags (`--tries=3 --timeout=120`, 8h `--max-time` rotation, `stopwaitsecs=1800`
so in-flight PDF jobs finish on release) are already in the conf.

## 5. Scheduler + backups (host cron)

```bash
sudo cp deploy/linux/cron-gw-system /etc/cron.d/gw-system && sudo chmod 0644 /etc/cron.d/gw-system
chmod 0755 deploy/linux/backup.sh
# edit backup.sh DB_* if they differ from .env
```

What fires (all times Asia/Manila, app-side scheduler in `routes/console.php`):

- `billing:run` — 1st 03:05, period = last month (queued; needs the worker up).
- `paymongo:reconcile` — daily 06:00, read-only discrepancy check (`paymongo` log).
- host `backup.sh` — daily 02:30 pg_dump rotation (before billing; never mid-billing).

Verification after install:

```bash
tail -f /var/log/gw-scheduler.log      # expect "Running scheduled command" once/min
ls -la /var/backups/gw-system/         # dump exists next morning
```

**Restore drill (mandatory, money data):** at least once before go-live,
`pg_restore --clean -d gw_system_test <dump>` onto a scratch DB and diff a row count.

## 6. nginx + TLS + domains

1. Point DNS A records for the app domain (and the frontend domain if on the same box).
2. `sudo certbot --nginx -d <domain>` (auto-renew timer is installed by certbot).
3. nginx `server {}` block for the Laravel app (root `backend/public`, `index.php`,
   `fastcgi` to `php8.5-fpm` socket, standard Laravel config — keep the `if (-f ...)`
   pattern for `index.php` + `$request_uri`).

**Frontend (Next.js):** marketing pages only today. Either build to a static export and
serve from the same nginx, or deploy to Vercel — set its `NEXT_PUBLIC_API_URL` to the
backend domain. Customer portal comes later.

## 7. Firewall + hardening

```bash
sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw enable
```

- `.env` is 640, owned by root — not world-readable, never committed (`.gitignore`).
- `APP_DEBUG=false`, `APP_ENV=production` (Filament `canAccessPanel` guard now satisfied
  via the `FilamentUser` contract — fix landed 2026-07-31, commit `de4ec0e`).
- `APP_URL` must be the HTTPS domain — notification/resend URLs are stored
  host-independent (2026-08-07) but `APP_URL` is the fallback.
- Optional later: fail2ban, Sentry (error tracking).

## 8. Go-live smoke (money-critical — manual, per project rule)

1. `https://<domain>/admin` → admin@gwsystem.com / admin123 → dashboard metrics render.
2. `POST https://<domain>/api/login` → Bearer token (Thunder Client: single
   `Accept: application/json` header — README §Testing the customer API).
3. PayMongo: set live webhook URL `https://<domain>/api/paymongo/webhook` in the
   dashboard; confirm the controller acks 200 within 30s and the signature secret
   matches `.env` (see `docs/manual-tests/paymongo-payment-e2e.md` for the test-mode
   flow; the CLI `paymongo:simulate-payment` is dev-only, not for livemode).
4. Pay a small real invoice → webhook marks paid, Payment row recorded, receipt email
   lands in Resend, admin bell quiet.
5. `php artisan paymongo:reconcile` → OK (no findings).
6. One `billing:run --period=<last month>` (queued) → `billing:report <id>` completed;
   re-run is idempotent ("Already billed") — re-run twice to prove it.
7. `SELECT count(*) FROM jobs;` ~0; `/var/backups/gw-system/` has a dump.
8. Stop the supervisor worker → bill a period → job waits in `jobs` → start worker →
   drains. (The exact failure mode the worker exists to fix.)

## 9. Release ritual (every deploy, no exceptions)

```bash
cd /var/www/gw-system && git pull
cd backend && composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
sudo supervisorctl status gw-system-worker*
```

A worker holds old config/code until `queue:restart`; skipping it = old behavior in
production. Scheduler entries need no cron changes (single `schedule:run` tick).

## 10. Dev vs prod differences (things that look like bugs but aren't)

| Dev (Windows) | Prod (Linux) |
|---|---|
| `cURL error 60` without CA bundle (README §PHP HTTPS/SSL) | Never happens — system CA bundle |
| Worker = manual terminal or Windows Scheduled Task | Worker = supervisor (this runbook §4) |
| Mailtrap / `MAIL_MAILER=log` | Resend + verified domain (SPF/DKIM/DMARC) |
| Postgres on localhost | Same native Postgres, `localhost` or socket |

## References

- `deploy/linux/supervisor-gw-worker.conf` — worker invocation (section 4).
- `deploy/linux/cron-gw-system`, `deploy/linux/backup.sh` — cron + backup (section 5).
- `backend/routes/console.php` — scheduler wiring (monthly billing, daily reconcile).
- `ARCHITECTURE.md` — Infra/Ops checklist; `docs/manual-tests/paymongo-payment-e2e.md`.
