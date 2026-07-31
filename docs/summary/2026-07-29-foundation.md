# Session Summary — 2026-07-29

## Goal
Establish the Foundation layer of GW-System: Postgres database, required packages, CORS configuration, and environment files.

## Software Installed on Windows

| Software | Source | Location |
|---|---|---|
| **PostgreSQL 18.4** | winget | `C:\Program Files\PostgreSQL\18\` |
| **PHP 8.5.8 (PHP Group)** | winget | `%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe\` |

## Pre-existing Software (not installed this session)
- **Herd Lite** — `%USERPROFILE%\.config\herd-lite\bin\php.exe` (PHP 8.5.0 NTS)
- **XAMPP** — stopped, not in PATH, not used

## Database Created
- `gw_system` on PostgreSQL
- User: `postgres` / Password: `postgres`
- Port: 5432
- Service name: `PostgreSQL` (set to Automatic startup)

## PHP Extensions Enabled (winget PHP 8.5.8)
openssl, curl, mbstring, fileinfo, gd, dom, zip, intl, pdo_pgsql, pgsql

## Composer Packages Installed
| Package | Version | Purpose |
|---|---|---|
| filament/filament | v5.7.3 | Admin panel at `/admin` |
| barryvdh/laravel-dompdf | v3.1.2 | PDF invoice generation |
| maatwebsite/excel | v3.1.68 | Excel exports/reports |

## Config Changes Made
| File | Change |
|---|---|
| `backend/.env` | Switched `DB_CONNECTION` from `sqlite` → `pgsql` |
| `backend/.env.example` | Added Postgres config block (commented out for production reference) |
| `backend/config/cors.php` | Restricted `allowed_origins` from `['*']` → `['http://localhost:3000']` |
| `frontend/.env.example` | Created with `NEXT_PUBLIC_API_URL=http://127.0.0.1:8000` |
| `frontend/.gitignore` | Added `!.env.example` exception so the template file can be tracked |
| `backend/bootstrap/providers.php` | Registered `Filament\AdminPanelProvider` |

## PATH Changes
- User PATH reordered: winget PHP `C:\Users\akwean\...\PHP.PHP.8.5_...` now comes **before** Herd Lite `%USERPROFILE%\.config\herd-lite\bin`
- PostgreSQL 18 `bin` directory added to User PATH
- Created `C:\php\ext` junction pointing to winget PHP's ext directory (so `extension_dir` resolves)

## Files Created
- `README.md` — root setup instructions with start/stop commands
- `backend/app/Providers/Filament/AdminPanelProvider.php` — Filament admin panel config
- `backend/public/css/`, `backend/public/fonts/`, `backend/public/js/` — Filament published assets
- `summary/2026-07-29-foundation.md` — this file

## Bug Encountered: "could not find driver (Connection: pgsql)"

**Root cause:** An old `php artisan serve` process was still running in the background, started by **Herd Lite PHP (8.5.0 NTS)**, which does not have `pdo_pgsql` loaded. Even after PATH was fixed to prefer winget PHP (8.5.8 ZTS with pgsql), the old server was still holding port 8000 and serving requests.

**Fix:** Kill the old server process (PID 2388) and restart `php artisan serve` with the correct PHP. A PC reboot would also work.

**Diagnosis notes:**
- The Laravel error page showed `PHP 8.5.0` at the bottom → confirmed Herd Lite was the one serving
- A fresh terminal showed `php -v` → `PHP 8.5.8` (winget) → correct PHP was on PATH, but the old process was still alive
- `php artisan serve` killing/restarting was necessary because processes don't re-evaluate PATH; they keep using the PHP binary they started with

## Git Commits This Session
| Hash | Message |
|---|---|
| `1597b64` | feat: foundation — Postgres, Filament, CORS, packages |
| `544920f` | docs: add root README with setup instructions |

## ARCHITECTURE.md Checklist Updated
- [x] Laravel 13 backend scaffolded, Postgres connected (local Postgres 18)
- [x] Next.js frontend scaffolded (marketing pages)
- [x] CORS configured between frontend and backend
- [x] `.env.example` files present for both apps

## Running the Project
```powershell
# Terminal 1 — Backend
cd backend
php artisan serve

# Terminal 2 — Frontend
cd frontend
npm run dev
```

- API: http://127.0.0.1:8000
- Admin: http://127.0.0.1:8000/admin
- Frontend: http://localhost:3000

## Next Step
Auth — Sanctum Bearer token login/revocation.
