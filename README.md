# GW-System — Guinobatan Waterworks System

An online billing and payment system for the Guinobatan Water District. Customers can
view and pay their water bills online; the office runs the billing, collects payments,
and manages connections from an admin panel.

---

## Table of Contents

1. [What is this?](#what-is-this)
2. [How the pieces fit together](#how-the-pieces-fit-together)
3. [Tech stack](#tech-stack)
4. [Before you start](#before-you-start)
5. [Option A: Automated setup (recommended)](#option-a-automated-setup-recommended)
6. [Option B: Manual setup, step by step](#option-b-manual-setup-step-by-step)
7. [Environment variables (`.env`)](#environment-variables-env)
8. [External service dashboards (sites you need to sign up for)](#external-service-dashboards-sites-you-need-to-sign-up-for)
9. [The database (PostgreSQL)](#the-database-postgresql)
10. [Running the app every day](#running-the-app-every-day)
11. [Payments & why ngrok is optional](#payments--why-ngrok-is-optional)
12. [Email in development (Mailtrap)](#email-in-development-mailtrap)
13. [Windows-specific notes](#windows-specific-notes)
14. [Testing the customer API](#testing-the-customer-api)
15. [Troubleshooting](#troubleshooting)
16. [Useful commands (cheat sheet)](#useful-commands-cheat-sheet)
17. [Project structure](#project-structure)
18. [Further reading](#further-reading)

---

## What is this?

A water-utility billing system with two halves:

| Who | What they see |
|---|---|
| **Customers** | A website (the "portal") where they can register, link their water connection, view their bills, and pay online with GCash / QR Ph / card |
| **Office staff** | An admin panel where they run monthly billing, track payments, manage service connections, import meter readings, and export reports |

Under the hood it's just three programs talking to each other (see below): a Laravel
backend that holds all the business logic, a Next.js frontend that customers use, and a
PostgreSQL database that stores everything.

---

## How the pieces fit together

```
Customer's browser
        │
        ▼
┌───────────────────────┐        ┌───────────────────────────┐
│  Next.js frontend     │  API   │  Laravel backend          │
│  (customer portal)    │ ─────► │  (JSON API + Filament     │
│  http://localhost:3000│        │   admin at /admin)        │
└───────────────────────┘        └───────────────────────────┘
        │                                 │            │
        │  PayMongo (payments)            │            │
        ▼                                 ▼            ▼
   GCash / QR Ph / Card            PostgreSQL 18    Queue worker
   (webhook confirms)              (the database)   (sends emails,
                                                     generates PDFs,
                                                     runs billing)
```

Key idea: **the customer portal never touches the database directly** — it calls the
Laravel API, which does the real work. Payments go through **PayMongo**; PayMongo calls
the backend back with a *webhook* when a payment succeeds, and the backend marks the
invoice paid and emails the receipt. That email is sent by a background **queue worker**
(see [Running the app every day](#running-the-app-every-day)).

---

## Tech stack

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

---

## Before you start

You need a Windows machine with internet access. Check that you have (or will install)
these tools:

| Tool | Version | Why |
|---|---|---|
| PHP | **8.5** | Runs the Laravel backend |
| Composer | 2.8+ | Installs PHP packages |
| Node.js + npm | Node 24+ / npm 11+ | Runs the Next.js frontend |
| PostgreSQL | 18 | The database (runs as a Windows service) |

You can check what you already have by opening a terminal and running:

```powershell
php -v          # must say 8.5.x — NOT an older/XAMPP version (see warning below)
composer -V
node -v
npm -v
```

> **⚠️ If you have XAMPP installed: stop it before doing anything else.**
>
> Open the **XAMPP Control Panel → Stop All → Exit** (make sure Apache and MySQL are
> stopped and the tray icon is closed).
>
> Why: XAMPP bundles its *own* old PHP, and if XAMPP is in your PATH, the `php` command
> will resolve to it instead of PHP 8.5 — this project then fails with errors like
> `could not find driver` (XAMPP's PHP has no Postgres support). XAMPP's MySQL is also
> **not** used here — this project uses PostgreSQL. If `php -v` still shows an XAMPP
> version after exiting XAMPP, open a *new* terminal and make sure `C:\xampp\php` is
> removed from the PATH environment variable.

---

## Option A: Automated setup (recommended)

No manual PHP/Postgres/Composer installation needed. Two files at the project root:

1. **`setup.bat`** — first time only. Installs and configures PHP 8.5 (php.ini,
   extensions, SSL CA bundle), Composer, Node.js, PostgreSQL 18, creates the databases,
   copies `backend/.env`, installs backend + frontend dependencies, runs migrations and
   seeds. Safe to re-run — every step detects what's already done and skips it.
2. **`start.bat`** — every time you develop. Opens two windows: **GW Backend + Queue**
   (API + admin + queue worker) and **GW Frontend** (customer portal).

```powershell
.\setup.bat    # first time only (re-run anytime; skips what's done)
.\start.bat    # then open http://localhost:3000 and http://127.0.0.1:8000/admin
```

**What you should see after `setup.bat`:** a green "Setup complete!" screen. The
migrations and seed also create two ready-made accounts:

| Role | URL | Email / Password |
|---|---|---|
| Admin panel (office) | http://127.0.0.1:8000/admin | `admin@gwsystem.com` / `admin123` |
| Customer portal | http://localhost:3000 | `test@example.com` / `password` |

**Sanity check:** `verify.ps1` prints `[ OK ]` / `[MISSING]` for every prerequisite —
run it before and after setup to prove a machine is ready:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File verify.ps1
```

> The manual 8-step install (winget, php.ini editing, SSL fix, PATH hacking) is
> preserved for reference in `docs/manual-setup.md` — only needed if the scripts
> can't be used on a given machine.

> **One thing setup.bat does NOT do:** it does not create `frontend/.env.local`. Copy it
> by hand once (2 files, 1 minute) — see [Environment variables](#environment-variables-env)
> below, "Frontend".

---

## Option B: Manual setup, step by step

Prefer `setup.bat` above. Do this only if the scripts can't run on your machine.

### Step 1 — Install the tools

```powershell
winget install PHP.PHP.8.5
winget install Composer.Composer          # or the installer from getcomposer.org
winget install OpenJS.NodeJS.LTS
winget install PostgreSQL.PostgreSQL.18   # a GUI installer may open — set the
                                          # superuser password to: postgres
```

Then open a **new** terminal (so PATH updates apply) and confirm: `php -v` (8.5.x),
`composer -V`, `node -v`, `npm -v`.

### Step 2 — Start PostgreSQL and create the databases

PostgreSQL runs as a Windows **service** named `PostgreSQL`:

```powershell
Start-Service PostgreSQL
```

Create the two databases (skip this if the service was installed with the default
`postgres` superuser password — this uses `postgres`):

```powershell
$env:PGPASSWORD = "postgres"
psql -U postgres -h 127.0.0.1 -c "CREATE DATABASE gw_system;"
psql -U postgres -h 127.0.0.1 -c "CREATE DATABASE gw_system_testing;"
```

### Step 3 — Backend: dependencies + database schema

```powershell
cd backend
Copy-Item .env.example .env         # copy the env template into a real .env
composer install                    # ⬅ installs all PHP packages (may take minutes)
php artisan key:generate            # ⬅ fills in APP_KEY in .env (required!)
php artisan migrate                 # ⬅ creates the tables in the database
php artisan db:seed                 # ⬅ demo data + the two logins (optional but handy)
```

`composer install` is the step most beginners miss — without it, `php artisan` dies
with "Class not found" errors. `php artisan key:generate` is the second one: without an
`APP_KEY` the app refuses to run.

### Step 4 — Frontend: dependencies + env file

```powershell
cd frontend
Copy-Item .env.example .env.local   # copy the env template into a real .env.local
npm install                         # ⬅ installs all JS packages (may take minutes)
```

### Step 5 — Start everything (3 terminals)

```powershell
# Terminal 1 — Backend (API + admin panel)
cd backend
php artisan serve

# Terminal 2 — Frontend (customer portal)
cd frontend
npm run dev

# Terminal 3 — Queue worker (background jobs: emails, PDFs, billing)
cd backend
php artisan queue:work --tries=3
```

See [Running the app every day](#running-the-app-every-day) for what to open and which
logins to use.

---

## Environment variables (`.env`)

Both apps read their settings from a `.env` file (backend) / `.env.local` file
(frontend) that sits **outside version control** — you create it by copying the
`.env.example` template. These files hold your API keys, so they are gitignored and
never committed.

### Backend — `backend/.env`

Created automatically by `setup.bat`; in manual setup you copy
`backend/.env.example` → `backend/.env` yourself.

| Variable | Purpose | Where to get it | Required? |
|---|---|---|---|
| `APP_KEY` | Encrypts sessions/data | `php artisan key:generate` | ✅ required |
| `DB_CONNECTION` | Must be `pgsql` | — (default in `.env.example` is `sqlite` — **change it!**) | ✅ required |
| `DB_HOST`, `DB_PORT` | `127.0.0.1`, `5432` | — | ✅ required |
| `DB_DATABASE` | `gw_system` | created in step 2 (or by setup.bat) | ✅ required |
| `DB_USERNAME`, `DB_PASSWORD` | `postgres` / `postgres` | set when you installed Postgres | ✅ required |
| `PAYMONGO_SECRET_KEY` | Server-side PayMongo calls | PayMongo dashboard → Developers → API Keys (`sk_test_...`) | ✅ required for payments |
| `PAYMONGO_PUBLIC_KEY` | Client-side card/GCash checkout | same page (`pk_test_...`) | ✅ required for payments |
| `PAYMONGO_WEBHOOK_SECRET` | Verifies webhook signatures | PayMongo dashboard → Developers → Webhooks (`whsk_...`) | only for real webhooks |
| `PAYMONGO_LIVEMODE` | `false` = test keys, `true` = real money | — | leave `false` in dev |
| `MAIL_MAILER` | `log` (no setup, writes to storage/logs) · `smtp` (Mailtrap) · `resend` (prod) | see [Email in development](#email-in-development-mailtrap) | ✅ (default `log` works) |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` | Mailtrap SMTP creds | Mailtrap → Email Testing → your inbox → SMTP Settings (per-inbox) | only if `MAIL_MAILER=smtp` |
| `RESEND_API_KEY` | Production email | resend.com → API Keys (`re_...`) | only for production |

The DB block in `.env` should look like this (the `.env.example` ships with SQLite
selected — switch it):

```ini
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=gw_system
DB_USERNAME=postgres
DB_PASSWORD=postgres
```

### Frontend — `frontend/.env.local`

> **Not created by `setup.bat` — copy it yourself:**

```powershell
cd frontend
Copy-Item .env.example .env.local
```

| Variable | Purpose | Where to get it | Required? |
|---|---|---|---|
| `NEXT_PUBLIC_API_URL` | Where the portal finds the Laravel API | `http://127.0.0.1:8000` (default) | ✅ required |
| `NEXT_PUBLIC_PAYMONGO_PUBLIC_KEY` | PayMongo in the browser (attach card/GCash) | PayMongo dashboard → API Keys — **same `pk_test_...` as the backend** | ✅ required for payments |

---

## External service dashboards (sites you need to sign up for)

The app talks to third-party services. You create free accounts on these sites and
paste the keys they give you into `.env`:

| Site | URL | What you get there / what it's for |
|---|---|---|
| **PayMongo** | https://dashboard.paymongo.com | Test payment keys (`pk_test_`/`sk_test_`) and webhook setup — the payment gateway (GCash, QR Ph, card) |
| **Mailtrap** | https://mailtrap.io | Free "Email Testing" inbox — receives the app's emails in dev so you can inspect receipts without spamming real inboxes |
| **Resend** | https://resend.com | Production email provider (verify a sending domain, get an API key) — deferred until go-live |
| **ngrok** | https://ngrok.com | Free public tunnel so PayMongo's real webhooks can reach your PC — optional, see [Payments](#payments--why-ngrok-is-optional) |

Only **PayMongo** is needed for a full payment test. Mailtrap is optional (email can
fall back to a log file), and ngrok is only needed for real webhook testing.

---

## The database (PostgreSQL)

- PostgreSQL is a **database server** that runs in the background as a Windows service
  named **`PostgreSQL`**.
- The app needs the service **running** or every page fails with a connection error.
- Two databases: **`gw_system`** (the app) and **`gw_system_testing`** (automated tests).
- Login: user **`postgres`**, password **`postgres`** (change in `backend/.env` if yours
  differs).

```powershell
Start-Service PostgreSQL     # start
Stop-Service PostgreSQL      # stop
```

Save resources when not developing (start manually only):

```powershell
Set-Service PostgreSQL -StartupType Manual
```

**Common commands** (run from `backend/`):

| Want to... | Command |
|---|---|
| Create/update tables | `php artisan migrate` |
| Load demo data + logins | `php artisan db:seed` |
| Wipe and start over | `php artisan migrate:fresh --seed` |
| Peek at the data | `psql -U postgres -h 127.0.0.1 gw_system` |

> **Gotcha:** Postgres is not installed via XAMPP. If you use XAMPP, its MySQL is a
> different system entirely — this project ignores it (see the warning in
> [Before you start](#before-you-start)).

---

## Running the app every day

### The easy way

```powershell
.\start.bat
```

This opens two windows: **GW Backend + Queue** (API + admin + queue worker) and
**GW Frontend** (customer portal). Close them with Ctrl+C when done.

### The manual way (3 terminals)

```powershell
# Terminal 1 — Backend (API + admin)
cd backend
php artisan serve

# Terminal 2 — Frontend (customer portal)
cd frontend
npm run dev

# Terminal 3 — Queue worker (background jobs)
cd backend
php artisan queue:work --tries=3
```

### What to open and which logins to use

| Service | URL | Login |
|---|---|---|
| Laravel API | http://127.0.0.1:8000 | — (JSON only) |
| Filament Admin | http://127.0.0.1:8000/admin | `admin@gwsystem.com` / `admin123` |
| Next.js Frontend | http://localhost:3000 | `test@example.com` / `password` |

### The queue worker (why you need Terminal 3)

The backend hands slow work — payment-confirmation emails (with the PDF receipt),
billing runs, SMS — to a **queue**. A *worker* (Terminal 3) picks those jobs up and runs
them in the background. **If the worker isn't running, jobs pile up silently in the
`jobs` table and emails/billing never happen** (payments themselves still work, but no
receipt arrives).

- Restart the worker after any `.env` change: `php artisan queue:restart`
- Check for a stuck backlog: `SELECT count(*) FROM jobs;` — should be ~0 at rest.

---

## Payments & why ngrok is optional

The payment flow: customer taps **Pay** → the backend asks **PayMongo** to create a
payment → the customer completes it (GCash / QR Ph scan / card) → **PayMongo calls the
backend back** with a *webhook* confirming payment → invoice marked paid → receipt
emailed.

That webhook call is the only part that needs the internet to reach *your* machine —
and **ngrok** is the tool that lets PayMongo's servers reach your local PC via a public
URL. So:

| Scenario | ngrok needed? |
|---|---|
| Basic development, card checkout, all other features | ❌ No |
| Offline payment simulation (`php artisan paymongo:simulate-payment`) | ❌ No — it fires the *exact same* webhook handler locally, no internet needed |
| Testing **real** PayMongo webhooks (scan a real QR with the GCash test app) | ✅ Yes |

That's why ngrok is optional: it's only for the real-webhook path. Everything else —
including the offline simulator, which exercises the identical code — works without it.

### If you do want real webhooks (ngrok)

```powershell
winget install ngrok.ngrok
ngrok config add-authtoken <your-token>    # free token from ngrok.com dashboard
ngrok http 8000
```

Copy the `https://xxx.ngrok-free.app` URL and paste it into **PayMongo Dashboard →
Developers → Webhooks → endpoint URL**, ending with `/api/paymongo/webhook`:

```
https://xxx.ngrok-free.app/api/paymongo/webhook
```

> **⚠️ ngrok's free tier gives you a NEW URL every time it restarts.** After every
> restart, update the PayMongo dashboard URL or webhooks silently fail. Check the
> current URL anytime: `http://127.0.0.1:4040/api/tunnels`

---

## Email in development (Mailtrap)

In dev, "sending" email goes to a **Mailtrap testing inbox** — you see the messages in
the Mailtrap website, nothing is actually delivered. The default `MAIL_MAILER=log`
writes every message to `backend/storage/logs` instead — fine for development, just
not as visual.

To see real receipts in the Mailtrap UI (one-time setup):

1. Sign up at https://mailtrap.io (free) → **Email Testing** → your inbox → copy its
   SMTP credentials (host `sandbox.smtp.mailtrap.io`, port `2525`, username/password —
   these are **per-inbox**).
2. In `backend/.env`:

   ```ini
   MAIL_MAILER=smtp
   MAIL_SCHEME=null
   MAIL_HOST=sandbox.smtp.mailtrap.io
   MAIL_PORT=2525
   MAIL_USERNAME=<mailtrap inbox username>
   MAIL_PASSWORD=<mailtrap inbox password>
   MAIL_FROM_ADDRESS=noreply@example.com
   MAIL_FROM_NAME="GW-System"
   ```

3. **Restart the queue worker** (`php artisan queue:restart`) — it caches config at
   startup.
4. Verify: `php artisan tinker` →
   `Mail::raw('hi', fn ($m) => $m->to('test@example.com')->subject('test'));`
   → the message appears in your Mailtrap inbox.

Production uses **Resend** (`MAIL_MAILER=resend` + `RESEND_API_KEY`) — deferred until
go-live.

---

## Windows-specific notes

### Two PHP installations on this machine

| Location | Notes |
|---|---|
| `%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.5_...\php.exe` | **Main** — has pgsql, intl, openssl enabled |
| `%USERPROFILE%\.config\herd-lite\bin\php.exe` | Fallback — Herd Lite, no pgsql |

The Winget PHP must be first in `PATH`. Use `php -v` to confirm you're on **8.5.8**
(PHP Group build) — and that it's not XAMPP's PHP (see [Before you start](#before-you-start)).

### HTTPS / SSL on Windows ("cURL error 60")

Windows does **not** ship a CA bundle, so PHP's cURL cannot verify outbound HTTPS
certificates by default. Every external API call from the backend (PayMongo, Resend,
Mailtrap) then fails with:

```
cURL error 60: SSL certificate OpenSSL verify result: unable to get local issuer certificate
```

`setup.bat` fixes this automatically. Manual recipe (for a new machine, or after a PHP
upgrade):

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
   Then hit PayMongo with a dummy key — a `401` JSON response means TLS is fine
   (before the fix this was `curl error 60`):
   ```powershell
   curl.exe -s -o NUL -w "%{http_code}" https://api.paymongo.com/v1/payment_intents
   ```

> **Why it matters for GW-System:** the `POST /api/invoices/{id}/pay` flow calls
> `PayMongoService` → `api.paymongo.com`. Without this fix it fails with a 502
> (`"Payment gateway unavailable"`) even though the PayMongo keys and code are correct.
> Don't chase it as a code bug — check `storage/logs/laravel.log` for `cURL error 60` first.

---

## Testing the customer API

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
| PayMongo API down / SSL broken | `502` `{"message":"Payment gateway unavailable..."}` — check `storage/logs/laravel.log` for `cURL error 60` first (see [Windows-specific notes](#windows-specific-notes)) |

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| `Class not found` / `composer` errors when running `php artisan` | You skipped **`composer install`** in `backend/` — run it |
| `VITE` / module-not-found errors on the portal | You skipped **`npm install`** in `frontend/` — run it |
| `could not find driver` / `Driver [] is not supported` | PHP is wrong (XAMPP's old PHP, no Postgres driver) — exit XAMPP, verify `php -v` shows 8.5.x |
| `Connection refused` / "database" errors | PostgreSQL service isn't running — `Start-Service PostgreSQL` |
| `Address already in use` for 3000/8000/5432 | Another program holds the port — XAMPP Apache on 80 is harmless, but check with `netstat -ano \| findstr <port>`; if it's a stray process, kill it or run `php artisan serve --port=8001` |
| `cURL error 60` / payment 502 | CA bundle missing — see [Windows-specific notes](#windows-specific-notes); check `storage/logs/laravel.log` |
| Invoice never marked paid after a payment | Queue worker not running — `jobs` table is filling; start `queue:work`. Or ngrok not running / webhook URL stale — restart ngrok and update the PayMongo dashboard |
| No email in Mailtrap | Wrong inbox creds (per-inbox) or no active link on the paid connection; restart worker after `.env` change |
| Webhook deliveries stuck "processing" | **Most common:** ngrok URL changed after restart — update PayMongo dashboard → Developers → Webhooks. Also: queue worker down, or ngrok not running |
| `/pay` returns 409 "already went through" | Intent previously succeeded — double-charge guard working; run `php artisan paymongo:reconcile` |

---

## Useful commands (cheat sheet)

| Purpose | Command (from `backend/` unless noted) |
|---|---|
| Start backend | `php artisan serve` |
| Start frontend | `cd frontend; npm run dev` |
| Queue worker | `php artisan queue:work --tries=3` (restart after `.env` change: `php artisan queue:restart`) |
| Simulate a payment offline | `php artisan paymongo:simulate-payment 4 --source=qrph` |
| Reconcile payments | `php artisan paymongo:reconcile` |
| Resend a receipt | `php artisan paymongo:send-receipt {invoice}` |
| Run billing + report | `php artisan billing:run --period=2026-07` · `php artisan billing:report {id}` |
| Reset demo data (full) | `php artisan test:seed-data --fresh` |
| Backend tests | `php -d memory_limit=512M vendor/phpunit/phpunit/phpunit` |
| Frontend tests / lint / build | `cd frontend; npm test` · `npm run lint` · `npm run build` |
| Start Postgres | `Start-Service PostgreSQL` |
| PayMongo reachability check | `curl.exe -s -o NUL -w "%{http_code}" https://api.paymongo.com/v1/payment_intents` (401 = reachable) |

> The full presentation-ready demo runbook (every phase, exact commands, what you
> should see) lives in `docs/showcase/README.md`.

---

## Project structure

```
gw-system/
  frontend/          Next.js + shadcn (customer-facing UI)
  backend/           Laravel + Filament (API + Admin)
  setup.bat          One-time automated setup (install everything)
  start.bat          Launch backend + frontend every day
  verify.ps1         Sanity check for the whole environment
  ARCHITECTURE.md    Architecture decisions & implementation status
  AGENTS.md          Development workflow rules
```

---

## Further reading

| Doc | What it covers |
|---|---|
| `docs/showcase/README.md` | Step-by-step demo runbook (API → portal → payments → email → admin → tests) with exact commands and expected output |
| `docs/manual-setup.md` | The full hand-run Windows install (every gotcha: php.ini, SSL, PATH) — reference only |
| `ARCHITECTURE.md` | Architecture decisions & implementation status checklist |
| `AGENTS.md` | Development workflow rules (for AI agents and humans) |
