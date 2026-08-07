# 2026-08-07 — Queue worker running (database driver) + dev workflow

## Goal
Close the Infra/Ops checklist item "Queue worker running (database driver)". Previously the
worker only ran when manually started in a terminal — queued jobs (webhook mark-paid,
confirmation emails, billing runs) silently accumulated in `jobs` otherwise. SMS skipped
per user decision (ties to the not-yet-built portal OTP flow; email feedback covers current
needs).

## Files created / modified
| File | What |
|---|---|
| `deploy/windows/queue-worker.ps1` (new) | Durable worker runner: pins working dir to `backend/`, resolves winget PHP (rejects no-`pdo_pgsql` builds), runs `php artisan queue:work --queue=default --tries=3 --timeout=120 --sleep=3 --max-time=28800`; self-restarts every 8h with a clean exit (relaunched by the loop), propagates non-zero exits so the task restarts on crash; `Start-Transcript` to `backend/storage/logs/queue-worker.log` |
| `deploy/windows/register-worker.ps1` (new) | Registers the `GW-System Queue Worker` Windows Scheduled Task (logon trigger, restart-on-failure 3×/1 min, no execution limit, IgnoreNew); smoke-runs `queue:work --once` first; `-Status` / `-Unregister` modes; friendly elevation guard (Task Scheduler needs admin) |
| `deploy/linux/supervisor-gw-worker.conf` (new) | Prod-host reference artifact (identical flags; `autorestart`, 8h `--max-time` rotation) — documented only, applied in the Infra phase |
| `backend/tests/Feature/QueueWorkerTest.php` (new) | 3 tests: (1) real DB-queue smoke — dispatch `QueuedProbeJob`, assert it sits in `jobs`, then drive it through the actual `queue:work` command and assert drain + side effect; (2) config falls back to `database` when `QUEUE_CONNECTION` is absent; (3) every `app/Jobs/*` class implements `ShouldQueue` and declares `$tries >= 3` |
| `backend/tests/Fixtures/{QueueProbe,QueuedProbeJob}.php` (new) | Dependency-free probe job + recorder for the smoke test |
| `README.md` | New "Queue worker (background jobs)" section: manual vs durable option, registration commands, operations (restart on `.env` change, `queue:failed`, `jobs` backlog check, job-level `tries` winning over CLI) |
| `ARCHITECTURE.md` | Queue & Background Jobs section updated (durable worker, test coverage); checklist item checked with notes |

## Bugs found & fixed (root cause)
1. **PowerShell 5.1 cannot parse non-ASCII in `.ps1` without a UTF-8 BOM.** Both scripts
   contained an em-dash (`—`, 3 UTF-8 bytes) inside string literals; PS 5.1 reads files as
   ANSI, mis-decoded the bytes and threw `The string is missing the terminator`. The worker
   silently never started (no log file). Fixed by making both scripts pure ASCII (verified
   0 non-ASCII bytes). Root cause is a file-encoding × interpreter mismatch, not script logic.
2. **Task registration needs elevation.** `Register-ScheduledTask` returns `Access is denied`
   in a non-admin shell. Added an explicit admin check with guidance (the smoke test still runs
   first, so PHP/queue problems are caught before the admin requirement).

## Test results
- New suite: `php -d memory_limit=512M vendor/phpunit/phpunit --filter QueueWorkerTest` — 3/3
  passed (24 assertions), including the DB-queue → `queue:work` → drain cycle.
- Full suite: **456/456 passed, 1616 assertions** (vs 343 before; counts include prior
  uncommitted export-fix work). `php -l` clean on all touched files; `pint` applied.
- Live operator test: launched `queue-worker.ps1` with `--max-time 3` in a controlled shell —
  launched the worker, hit max-time, relaunched, transcript written. Registered + started the
  real Scheduled Task (elevated UAC); `register-worker.ps1 -Status` → **State: Running**, log
  shows a worker active with the durable flags.

## Known gaps / next step
- The old manual dev worker (`php artisan queue:work --tries=3`, started 08-06, PID 17012) is
  still running alongside the task. Postgres `FOR UPDATE SKIP LOCKED` makes two workers safe
  (each job processed exactly once) but the manual one is now redundant — user may Ctrl+C it.
- Monthly billing still manual (scheduler is Infra phase — needs cron on the eventual host).
- `graphify . --update` not run (extra-driven: new test fixtures + deploy scripts only; no
  shared-code structural change — run during the next structural session).

## Next step (recommended)
"Basic rate limiting on public API routes" — app-level, host-independent (only `/api/login`
`throttle:10,1` and `POST /api/invoices/{id}/pay` `throttle:20,1` carry limits today), suitable
for a single-session item before the host decision that unblocks the rest of Infra/Ops.

## Follow-up (same day, "server, not laptop" course correction)
User flagged the laptop Scheduled Task as missing the point: the worker that matters is
the one on the host that will serve this. No host chosen yet, so the phase pivoted to
**server-readiness artifacts that ship with the repo** (apply at go-live):

- `backend/routes/console.php` — Laravel scheduler wiring: `billing:run` 1st 03:05 PH
  (explicit `--period` = previous month end computed in Asia/Manila, `withoutOverlapping`,
  queued by default) + `paymongo:reconcile` daily 06:00 PH. Server needs one cron line.
- `deploy/linux/cron-gw-system` — the `schedule:run` 1-min tick + 02:30 pg_dump line.
- `deploy/linux/backup.sh` — rotating `pg_dump -Fc`, keep 15, before-billing slot.
- `docs/deployment-runbook.md` — fresh VPS → go-live sequence (packages, Postgres, app,
  supervisor worker, cron/backup, nginx+TLS, firewall, manual money-flow smoke, release
  ritual, dev-vs-prod diff table). Flags the **memory_limit=512M dompdf landmine** on the
  server (worker + php-fpm), the same failure class we hit in tests at 128M.
- `tests/Feature/ScheduleTest.php` — asserts both entries, cron expressions
  (`5 3 1 * *` / `0 6 * * *`), Asia/Manila timezone, `withoutOverlapping` (via the
  container singleton the `withRouting(commands:)` bootstrap populates).
- `ARCHITECTURE.md` / `README.md` — wording made honest: worker running **in dev**,
  host artifacts **ready**, host install is an Infra action.

Test results: ScheduleTest 3/3; full suite re-run green (see below).

## Commit
Not committed (needs explicit approval). Bundle: deploy scripts + tests + fixtures + README +
ARCHITECTURE checkbox + session summary.