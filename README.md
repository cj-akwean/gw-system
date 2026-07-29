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
| Email (dev) | Mailtrap (via `MAIL_MAILER=log` for now) |

## Prerequisites

- **PHP 8.5** (see notes below)
- **Composer** 2.8+
- **Node.js** 24+ / **npm** 11+
- **PostgreSQL 18** (Windows service)

## PHP Notes

Two PHP installations on this machine:

| Location | Notes |
|---|---|
| `%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.5_...\php.exe` | **Main** — has pgsql, intl, openssl enabled |
| `%USERPROFILE%\.config\herd-lite\bin\php.exe` | Fallback — Herd Lite, no pgsql |

The Winget PHP is first in `PATH`. Use `php -v` to confirm you're on **8.5.8** (PHP Group build).

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
