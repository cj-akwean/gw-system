# 2026-08-10 — Automated Windows setup scripts (setup.bat / start.bat / verify.ps1)

## Goal

Replace the 8-step manual Windows fresh-install (winget + php.ini editing + SSL CA
fix + PATH hacking) with double-clickable automation, after the user rejected Docker
(too heavy for low-spec laptops).

## Files created / modified

| File | Action | What |
|---|---|---|
| `setup.bat` | new | Wrapper: `powershell -ExecutionPolicy Bypass -File setup.ps1` + pause |
| `setup.ps1` | new | Full installer: winget PHP 8.5 / Composer / Node LTS / PostgreSQL 18; creates php.ini, enables extensions, sets memory_limit, downloads CA bundle (cURL 60 fix), adds PATHs, creates DBs, composer install, migrate, seed, npm install. Fully idempotent — every step detects and skips. |
| `start.bat` | new | Opens two windows: `composer dev` (backend + queue) and `npm run dev` (frontend) |
| `verify.ps1` | new | Read-only prerequisite report `[ OK ]` / `[MISSING]` (winget, PHP+ini+exts+CA, Composer, Node, psql+service+DBs, vendor/.env/APP_KEY/node_modules) |
| `README.md` | modified | `## Quick Start` section added; the whole 8-step "Windows Fresh Install" section (178 lines) replaced with a pointer; git diff: 21 insertions, 178 deletions |
| `docs/manual-setup.md` | new | The original 8-step install + troubleshooting, kept as reference |

## Bugs found & fixed

1. **PowerShell 5.1 + UTF-8 (root cause, script-breaking):** `.ps1` files written as
   UTF-8 without BOM are parsed as ANSI. Em-dashes (—, U+2014) decode to cp1252
   bytes where `0x94` becomes a smart-quote char, which PowerShell treats as a string
   delimiter → `Unexpected token` parse errors in `setup.ps1`. Fix: file must be
   pure ASCII (replaced all em-dashes with hyphens). Verify: `Parser::ParseFile`
   returned 0 errors.
2. **bcmath false assumption:** PHP 8.5.8 (this ZTS WinGet build) has bcmath compiled
   in but ships **no** `php_bcmath.dll` → `extension=bcmath` in php.ini emits a
   startup warning on every PHP call and corrupts captured `php -r` output. Removed
   bcmath from the enable list (it's not in the project's required set), and reverted
   the line my test run had written into the real `php.ini`.
3. **Native stderr under `$ErrorActionPreference=Stop`:** `& php -m` / `php -r` can
   emit stderr warnings that pollute captured output. Fixed with `2>$null` +
   `Select-Object -Last 1` on the `php -r` CA-bundle checks in both scripts.
4. **Postgres service name:** it's `postgresql-x64-18`, not `PostgreSQL`. Both
   scripts now match `*postgresql*` via `Get-Service | Where-Object`.
5. **README encoding corruption (process fix):** my first attempt to splice the
   README with `Get-Content`/`Set-Content` re-wrote the UTF-8 file as ANSI and
   mangled every em-dash into `â€"`. Recovered with `git checkout -- README.md` and
   redid the edit with the Edit tool (UTF-8 safe). Rule learned: never round-trip
   UTF-8 files through PS 5.1 default encoding.

## Test results (actually verified)

- `verify.ps1` — all rows `[ OK ]` (run twice, after each change)
- `setup.ps1` on this already-configured machine — idempotent pass: every install
  step reported "already done, skipping"; migrate "Nothing to migrate"; db:seed ran
  clean; no warnings.
- `start.bat` — launched, verified `GET http://127.0.0.1:8000/api/rates` → 200 and
  `GET http://localhost:3000` → 200 (HTML matched). All 16 spawned processes
  (php/node/cmd) were killed afterward; ports 3000/8000 back to free.
- Parser check: `setup.ps1` and `verify.ps1` both 0 parse errors.

**Not verified:** a true fresh-machine run (no tools installed). The winget install
branches (PHP/Composer/Node/Postgres) and the php.ini-from-template branch are
untested paths. Postgres winget install may still pop a GUI — the script detects
missing psql after install and tells the user to finish the GUI + re-run.

## Known gaps / next step

- Fresh-machine dry run (VM or spare laptop) to exercise the install branches.
- Consider `verify.ps1` exit code (currently informational only).

## Git

Not committed (rule: no commit unless asked).
