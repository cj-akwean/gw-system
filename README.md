# GW-System — Guinobatan Waterworks System

## Stack

| Layer | Technology |
|---|---|
| Frontend | Next.js 16 + shadcn/ui + Tailwind v4 |
| Backend | Laravel 13 + Filament v5.7 + Postgres |
| Payments | PayMongo (one-off billing) |
| PDF Generation | dompdf (barryvdh/laravel-dompdf) |
| Exports / Reports | Laravel Excel (maatwebsite/excel) |
| API Auth | Laravel Sanctum (Bearer tokens) |
| Queue | Database driver |
| Email (prod) | Resend |
| Email (dev) | Mailtrap (fallback: `MAIL_MAILER=log` — writes to `storage/logs`, nothing delivered) |

## Prerequisites

- **PHP 8.5** (see notes below)
- **Composer** 2.8+
- **Node.js** 24+ / **npm** 11+
- **PostgreSQL 18** (Windows service)

## Windows Fresh Install (step-by-step — read this first on a new machine)

The steps above are concise but hide several real problems you will hit on a fresh
Windows install. This section documents the **actual** sequence that works, including
every gotcha encountered during setup on 2026-08-09.

### Step 1: Install PHP via winget

```powershell
winget install --id=PHP.PHP.8.5
```

**Problem:** Winget installs PHP but does **not** create an active `php.ini`. Only
`php.ini-development` and `php.ini-production` templates exist. PHP loads with zero
extensions and no memory limit override. This breaks everything downstream.

**Fix — create php.ini and enable required extensions:**

```powershell
$phpDir = "$env:LOCALAPPDATA\Microsoft\WinGet\Packages\PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe"

# Copy the dev template
Copy-Item "$phpDir\php.ini-development" "$phpDir\php.ini"
```

Then edit `$phpDir\php.ini` and make these changes:

1. **Set extension_dir** (around line 758) — the default is commented out and PHP
   defaults to `C:\php\ext` which does not exist:
   ```ini
   extension_dir = "C:\Users\<YOU>\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe\ext"
   ```

2. **Enable these extensions** (remove the `;` from each):
   ```ini
   extension=curl
   extension=openssl
   extension=pdo_pgsql
   extension=pgsql
   extension=mbstring
   extension=fileinfo
   extension=intl
   extension=zip
   ```

3. **Set memory limit** (around line 428):
   ```ini
   memory_limit = 512M
   ```
   (dompdf OOMs at the default 128M when generating PDFs.)

**Verify:**
```powershell
php -m | findstr curl        # should print "curl"
php -m | findstr pdo_pgsql   # should print "pdo_pgsql"
php -r "echo ini_get('memory_limit');"  # should print "512M"
```

### Step 2: PHP SSL / HTTPS (cURL error 60 fix)

Windows does not ship a CA bundle. Without it, every outbound HTTPS call from PHP
(PayMongo, Resend, Mailtrap) fails with `cURL error 60`.

```powershell
$phpDir = "$env:LOCALAPPDATA\Microsoft\WinGet\Packages\PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe"

# Download the CA bundle
Invoke-WebRequest -Uri "https://curl.se/ca/cacert.pem" -OutFile "$phpDir\cacert.pem"
```

Then in the same `php.ini`, add these under their respective sections:

```ini
[curl]
curl.cainfo = "C:\Users\<YOU>\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe\cacert.pem"

[openssl]
openssl.cafile= "C:\Users\<YOU>\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe\cacert.pem"
```

**Verify:**
```powershell
php -r "echo ini_get('curl.cainfo'), PHP_EOL;"
# must print the .pem path (not empty)
```

### Step 3: Install Composer

The standard Composer installer uses PHP's `copy()` function which requires HTTPS —
which does not work until Step 2 is done. Do Steps 1-2 first, then:

```powershell
New-Item -ItemType Directory -Path "C:\composer" -Force
Invoke-WebRequest -Uri "https://getcomposer.org/installer" -OutFile "C:\composer\composer-setup.php"
php "C:\composer\composer-setup.php" --install-dir=C:\composer --filename=composer
Remove-Item "C:\composer\composer-setup.php"
```

Add `C:\composer` to your user PATH:
```powershell
[Environment]::SetEnvironmentVariable("Path", [Environment]::GetEnvironmentVariable("Path", "User") + ";C:\composer", "User")
```

**Verify:** open a **new** terminal, then:
```powershell
composer --version
```

### Step 4: Install Node.js

```powershell
winget install --id=OpenJS.NodeJS.LTS
```

Or use nvm (if already installed):
```powershell
nvm install 24
nvm use 24
```

### Step 5: Install PostgreSQL

```powershell
winget install --id=PostgreSQL.PostgreSQL.18
```

When prompted, set the superuser password to `postgres` (matching the `.env` config).

**Problem:** The PostgreSQL `bin` directory is not added to PATH automatically. You
must add it manually or `psql` will not be found.

```powershell
# Add to user PATH (run in PowerShell)
[Environment]::SetEnvironmentVariable("Path", [Environment]::GetEnvironmentVariable("Path", "User") + ";C:\Program Files\PostgreSQL\18\bin", "User")
```

Then open a **new** terminal and create the databases:
```powershell
$env:PGPASSWORD = "postgres"
psql -U postgres -c "CREATE DATABASE gw_system;"
psql -U postgres -c "CREATE DATABASE gw_system_testing;"
```

### Step 6: Project setup

```powershell
cd backend
composer install
composer setup    # runs: key:generate, migrate, npm install, npm run build
```

### Step 7: Seed data + create admin

```powershell
cd backend
php artisan db:seed
```

This creates:
- Admin user: `admin@gwsystem.com` / `admin123`
- Test user: `test@example.com` / `password`
- Barangays, rate schedules, penalty rules, demo portal data

### Step 8: Run the app (3 terminals)

```powershell
# Terminal 1 — Backend
cd backend
php artisan serve

# Terminal 2 — Frontend
cd frontend
npm run dev

# Terminal 3 — Queue worker
cd backend
php artisan queue:work --tries=3
```

### Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `could not find driver` on `artisan migrate` | `pdo_pgsql` extension not enabled | Uncomment `extension=pdo_pgsql` in php.ini |
| `Call to undefined function mb_strimwidth()` | `mbstring` extension not enabled | Uncomment `extension=mbstring` in php.ini |
| `cURL error 60` in `laravel.log` | No CA bundle configured | Follow Step 2 above |
| `memory_limit` errors on PDF generation | Default 128M too low | Set `memory_limit = 512M` in php.ini |
| `psql: command not found` | PostgreSQL not in PATH | Add `C:\Program Files\PostgreSQL\18\bin` to PATH |
| `composer: command not found` | Composer not in PATH | Add `C:\composer` to PATH or use `php C:\composer\composer` |
| Extension loads but PHP says "module not found" | `extension_dir` points to wrong path | Set `extension_dir` to the WinGet `ext` folder in php.ini |

## Optional Dev Tools

### Graphify (knowledge graph for codebase analysis)

Used in the development workflow to query code relationships before editing shared code.
Requires Python 3.10+.

```powershell
# Install Python (if not already installed)
winget install Python.Python.3.12

# Install uv + graphify
pip install uv
uv tool install graphifyy

# Register globally with opencode (run from ANY directory)
graphify install --platform opencode
```

Usage (from AGENTS.md):
- `graphify query "<term>"` — find what depends on a function/class
- `graphify . --update` — refresh the graph after structural changes
- `.graphifyignore` excludes `backend/vendor/` and `backend/public/js/`

> **Note:** Run `graphify install --platform opencode` from your home directory (not
> inside a project) so the plugin registers globally in `~/.config/opencode/`.

### ngrok (local webhook tunnel for PayMongo testing)

Only needed if you want to test real PayMongo webhooks locally (not required for
the simulator or basic development).

```powershell
winget install ngrok.ngrok
```

Then connect your account:
```powershell
ngrok config add-authtoken <your-token>   # get token from ngrok.com dashboard
```

Usage:
```powershell
ngrok http 8000
# Copy the https://xxx.ngrok-free.app URL
# Update PayMongo dashboard webhook URL to: https://xxx.ngrok-free.app/api/paymongo/webhook
```

> **Note:** ngrok free tier issues a new URL on every restart. Update the PayMongo
> dashboard URL each time. Check current URL: `http://127.0.0.1:4040/api/tunnels`

## PHP Notes

Two PHP installations on this machine:

| Location | Notes |
|---|---|
| `%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.5_...\php.exe` | **Main** — has pgsql, intl, openssl enabled |
| `%USERPROFILE%\.config\herd-lite\bin\php.exe` | Fallback — Herd Lite, no pgsql |

The Winget PHP is first in `PATH`. Use `php -v` to confirm you're on **8.5.8** (PHP Group build).

## PHP HTTPS / SSL (mandatory on Windows — "cURL error 60")

Windows does **not** ship a CA bundle, so PHP's cURL cannot verify outbound HTTPS
certificates by default. Every external API call from the backend (PayMongo, Resend,
Mailtrap, any HTTPS endpoint reached with `Http::`) fails with:

```
cURL error 60: SSL certificate OpenSSL verify result: unable to get local issuer certificate
```

This machine is **already fixed** — the steps below are the repeat recipe for a new
machine / fresh PHP install, or if the error comes back after a PHP upgrade:

1. Download the Mozilla/curl CA bundle:
   ```powershell
   Invoke-WebRequest -Uri "https://curl.se/ca/cacert.pem" `
     -OutFile "$env:LOCALAPPDATA\Microsoft\WinGet\Packages\PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe\cacert.pem"
   ```
2. In that same directory's `php.ini` (run `php --ini` to find it), uncomment and set:
   ```ini
   [curl]
   curl.cainfo = "C:\Users\akwean\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe\cacert.pem"

   [openssl]
   openssl.cafile="C:\Users\akwean\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe\cacert.pem"
   ```
3. **Restart any running `php artisan serve`** — it reads php.ini only at startup.
4. Confirm the fix:
   ```powershell
   php -r "echo ini_get('curl.cainfo'), PHP_EOL;"
   # must print the .pem path (not empty)
   ```
   Then hit PayMongo with a dummy key — a `401` JSON response means TLS verified (before the
   fix this was `curl error 60`):
   ```powershell
   curl.exe -s -o NUL -w "%{http_code}" https://api.paymongo.com/v1/payment_intents
   ```
   (401 = reachable. A `command not found`-style output is unrelated to this check.)

> **Why it matters for GW-System:** the `POST /api/invoices/{id}/pay` flow calls
> `PayMongoService` → `api.paymongo.com`. Without this fix it fails with a 502
> (`"Payment gateway unavailable"`) even though the PayMongo keys and code are correct.
> Don't chase it as a code bug — check `storage/logs/laravel.log` for `cURL error 60` first.

## Email (dev) with Mailtrap

Payment confirmations (invoice PDF attachment) are sent by a queued job. In dev, mail goes to a
**Mailtrap** testing inbox (captured in the web UI, never delivered) unless you opt out:
`MAIL_MAILER=log` writes every message to `storage/logs` instead of sending.

Setup (one-time):

1. Sign up at mailtrap.io (free) → **Email Testing** → your inbox → copy its SMTP credentials
   (host `sandbox.smtp.mailtrap.io`, port `2525`, username/password — these are **per-inbox**).
2. In `backend/.env`:
   ```
   MAIL_MAILER=smtp
   MAIL_SCHEME=null
   MAIL_HOST=sandbox.smtp.mailtrap.io
   MAIL_PORT=2525
   MAIL_USERNAME=<mailtrap inbox username>
   MAIL_PASSWORD=<mailtrap inbox password>
   MAIL_FROM_ADDRESS=noreply@example.com
   MAIL_FROM_NAME="GW-System"        # optional; defaults to APP_NAME
   ```
3. **Start `php artisan queue:work` in a second terminal** — queued emails (payment
   confirmations, identifier-change notifications), SMS, PDFs, and billing runs all need
   a running worker. Without it, jobs accumulate silently in the `jobs` table.
   Restart the worker after any `.env` mail change (it caches config at startup).
4. Verify: `php artisan tinker` → `Mail::raw('hi', fn ($m) => $m->to('test@example.com')->subject('test'));`
   → message appears in the Mailtrap inbox.

The full payment→email round is scripted in `docs/manual-tests/paymongo-payment-e2e.md`
(addendum "email delivery verification" — includes the link-the-connection gotcha that silently
skips the email).

Production uses **Resend** (`MAIL_MAILER=resend` + `RESEND_API_KEY`, sending domain verified in
Resend with SPF/DKIM/DMARC) — deferred until go-live; Laravel 13 ships the resend transport
natively.

## PostgreSQL

- **Service name:** `PostgreSQL`
- **Database:** `gw_system`
- **User:** `postgres` / **Password:** `postgres`

```powershell
Start-Service PostgreSQL     # start
Stop-Service PostgreSQL      # stop
```

Set to Manual startup to save resources when not developing:

```powershell
Set-Service PostgreSQL -StartupType Manual
```

## Getting Started

```bash
# Terminal 1 — Backend (API + Admin)
cd backend
php artisan serve

# Terminal 2 — Frontend (Customer UI)
cd frontend
npm run dev
```

### Create an admin user (first time)

```bash
cd backend
php artisan make:filament-user
```

## Queue worker (background jobs)

The queue uses the **database** driver. Payment-confirmation emails (with the PDF
attachment), connection-identifier-change emails, webhook `mark paid` jobs, and billing
runs are all queued — **a running worker is required** or jobs sit in the `jobs` table
silently. How to run it:

**Dev (this machine):** run the worker manually in a second terminal, from `backend`:

```bash
php artisan queue:work --tries=3       # (or --once to process one job and exit)
```

An auto-start Windows Scheduled Task was tried and **removed (2026-08-07)** — it
pegged the dev laptop's disk at 100% at every boot (continuous polling plus unbounded
log growth). Dev runs the worker in a terminal only; there is no auto-start.

**Production (Linux host):** the same command runs under Supervisor
(`deploy/linux/supervisor-gw-worker.conf`) with `--tries=3 --timeout=120 --sleep=3`,
an 8-hour self-restart (`--max-time`) to shed memory and stale config, and rotating
logs (`stdout_logfile_maxbytes=10MB`, `stdout_logfile_backups=5`) so worker output can
never fill the disk. Plus the host cron + backup in `deploy/linux/` and the full
sequence in `docs/deployment-runbook.md` — the live worker is an Infra-phase action
on the host you choose.

**Backups:** the same `deploy/linux/backup.sh` daily `pg_dump -Fc` produces onto the
host. Backups are confirmed with `deploy/linux/restore-drill.sh` (restores into a
scratch DB and drops it — a backup that was never restored is a rumor); credentials on
a real host go in a root-only `/etc/gw-backup.env` that the cron line sources.

Operational notes:

- **After any `.env` / queue-config change**, restart the worker with
  `php artisan queue:restart` — it caches config at startup.
- **Failed jobs** land in `failed_jobs`; inspect with `php artisan queue:failed`.
  Receipt failures additionally raise the admin bell ("Resend receipt").
- **Check for a stuck backlog:** `SELECT count(*) FROM jobs;` — should be ~0 at rest.
- Jobs declare their own `tries = 3`, which wins over any worker CLI `--tries` — so
  the `composer dev` helper's `--tries=1` never throttles a real job's retries.

## Testing the customer API (Thunder Client / REST clients)

The customer portal is a **JSON API** (`POST /api/login` → Bearer token → authenticated
routes). When testing with Thunder Client or any REST client, two header rules apply:

1. **Every request needs `Authorization: Bearer <token>`** (except `/api/login`).
2. **Send `Accept: application/json` — and only that.** Thunder Client injects a default
   `Accept: */*` header automatically; do **not** add a second `Accept:
   application/json` row on top of it, because the first `Accept` wins and Laravel
   treats the request as a browser request.

   Symptom of the duplicate-header mistake: an unauthenticated call returns
   **500 `Route [login] not defined`** instead of a clean `401 {"message":"Unauthenticated."}`.
   Fix: in the request's Headers tab, **edit** the existing `Accept` row to
   `application/json` — don't add a second row.

   The backend also names the login route `login` (2026-08-04), so headerless requests
   no longer crash — but only `Accept: application/json` yields the proper 401 JSON.

Quick smoke test sequence:

```text
POST http://127.0.0.1:8000/api/login      {"email":"test@example.com","password":"password"}
    → {"token":"1|...","user":{...}}        (copy the token)

POST http://127.0.0.1:8000/api/links        headers: Bearer <token>
    {"account_number":"GW-00001","meter_number":"MTR-00001"}

POST http://127.0.0.1:8000/api/invoices/{id}/pay   headers: Bearer <token>
    → {"client_key":"pi_...","payment_intent_id":"pi_..."}
```

Expected edge cases:

| Call | Result |
|---|---|
| No Bearer token | `401` `{"message":"Unauthenticated."}` |
| Pay an already-paid invoice | `409` `{"message":"Invoice is already paid."}` |
| Invoice of a connection you're not linked to | `403` `{"message":"Forbidden"}` |
| PayMongo API down / SSL broken | `502` `{"message":"Payment gateway unavailable..."}` — check `storage/logs/laravel.log` for `cURL error 60` first (see PHP HTTPS / SSL above) |

## Links

| Service | URL |
|---|---|
| Laravel API | http://127.0.0.1:8000 |
| Filament Admin | http://127.0.0.1:8000/admin |
| Next.js Frontend | http://localhost:3000 |

## Directory Structure

```
gw-system/
  frontend/          Next.js + shadcn (customer-facing UI)
  backend/           Laravel + Filament (API + Admin)
  ARCHITECTURE.md    Architecture decisions & implementation status
  AGENTS.md          Development workflow rules
```
