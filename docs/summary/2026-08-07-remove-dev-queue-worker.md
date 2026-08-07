# 2026-08-07 — Remove dev auto-start queue worker (Windows Scheduled Task)

## Goal
The "durable worker" from commit `296f7ea` registered a Windows Scheduled Task
(`GW-System Queue Worker`) that started `php artisan queue:work` at every logon and
kept a PowerShell transcript running. On the dev laptop this pegged disk usage at
100% shortly after boot. Decision (user-confirmed): **no auto-start on dev** — run
the worker manually in a terminal. Production artifacts stay, with log rotation.

## Files changed
| File | What |
|---|---|
| `deploy/windows/queue-worker.ps1` | **DELETED** (was the continuous runner with `Start-Transcript`) |
| `deploy/windows/register-worker.ps1` | **DELETED** (was the Task Scheduler registration/status/unregister helper) |
| `deploy/windows/` | Directory now empty |
| `README.md` | "Queue worker" section: manual terminal only on dev; removed the 3-command scheduled-task recipe; production supervisor paragraph now notes rotating logs |
| `ARCHITECTURE.md` | "Worker strategy (2026-08-07)" bullet + Infra checklist item rewritten — dev = manual, prod = supervisor artifact, auto-start removed |
| `docs/deployment-runbook.md` | Dev/prod table row: "Worker = manual terminal" |
| `deploy/linux/supervisor-gw-worker.conf` | Header comment: dropped dead `deploy/windows/` reference, documented that logs rotate (already `stdout_logfile_maxbytes=10MB` × 5 backups) — prod was already log-safe, no flag changes |

## Machine-side action (not in git)
- Unregistered the `GW-System Queue Worker` scheduled task via an elevated
  PowerShell (`Unregister-ScheduledTask`). Verified: task GONE.
- `backend/storage/logs/queue-worker.log` was only 3.9 KB at removal — not the disk
  culprit; the 100% was the task's continuous polling + transcript at boot time.

## Root cause of the 100% disk (diagnosis)
The task started the worker at logon (booting is exactly when Windows Update,
OneDrive, antivirus and the worker all hit the disk at once) and `Start-Transcript`
appended every worker line to `queue-worker.log` with no rotation. Continuous
database polling (`--sleep=3`) plus unbounded logging = heavy sustained disk I/O.

## Test results
- `QueueWorkerTest` + `ScheduleTest`: 6/6 passed, 35 assertions
  (`php -d memory_limit=512M vendor/phpunit/phpunit/phpunit ...`). Neither test
  referenced the deleted scripts.

## Known gaps / next step
- No commit yet (needs explicit approval; repo also has unrelated uncommitted
  portal-shell work in progress — commit only the worker-removal bundle or hold).
- Manual verify: reboot → no `php`/`queue:work` process starts; run
  `php artisan queue:work --tries=3` in a terminal when testing queued jobs.
- `graphify . --update` **not completed**: this session has no LLM API key (semantic
  extraction for 45 doc files needs one) and the run flagged a stale baseline
  (17,458 files "deleted" vs the old manifest — the AGENTS.md warning sign). Run it
  in a normal session with the key configured and verify the manifest first.
