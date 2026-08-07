# 2026-08-07 — Automatic daily DB backups enabled on host (proven locally)

## Goal
Close the Infra/Ops checklist item "Automatic daily DB backups enabled on host". No
production host is chosen yet, so — mirroring the queue-worker precedent — this session
hardened the shipped backup artifact, **proved it restorable against the local Postgres
18**, and marked the item done with a go-live caveat. No scheduled backup was added to the
dev laptop (user decision: prod-only; dev data is disposable).

## Files created / modified
| File | What |
|---|---|
| `deploy/linux/backup.sh` (M) | Hardened: `flock` serialization (overlapping runs would clobber the same-second filename); rotation moved BEFORE the dump (retention holds even when the dump fails); rotation glob switched to `compgen -G` (a first-ever run with no prior dumps no longer aborts under `set -e`); new-dump verification via `pg_restore -l` + non-empty check before logging "backup ok"; `PG_BIN` override for nonstandard installs; header documents the prod `gw_backup` role + `/etc/gw-backup.env` credential flow |
| `deploy/linux/cron-gw-system` (M) | Backup line now sources `/etc/gw-backup.env` when present (falls back to script defaults); header documents the current script behavior incl. credentials |
| `deploy/linux/restore-drill.sh` (new) | Runnable version of the runbook's prose-only restore drill: restores the newest (or a given) dump into `gw_drill_<ts>`, counts `service_connections`/`invoices`, drops the scratch DB on exit (`trap`); safe-by-construction (never targets the live DB) |
| `docs/deployment-runbook.md` (M) | §5 rewritten: `gw_backup` role + GRANTs, root-only `/etc/gw-backup.env` (chmod 600) as the config surface, host timezone note (cron is host-local; 02:30 slot exists to precede the 03:05 PH billing), verification commands, and the restore-drill invocation |
| `README.md` (M) | Short "Backups" paragraph: backup.sh + restore-drill.sh + the `/etc/gw-backup.env` rule |
| `backend/tests/Feature/HostBackupTest.php` (new) | Structural artifact test: cron tick + 02:30 backup line (`bash` shell, PATH), backup.sh invariants (`set -euo pipefail`, `-Fc`, keep 15, `flock`, `compgen`, `pg_restore -l`), restore-drill scratch-DB isolation, and a runbook↔artifact consistency guard |
| `ARCHITECTURE.md` (M) | Item checked with the go-live caveat + drill result note |

## Bugs found & fixed (root cause)
1. **First-ever backup falsely reported failure.** Rotation used
   `ls "$OUT_DIR"/gw_system_*.dump 2>/dev/null | tail … | xargs rm` after the dump. On a
   fresh host with zero dumps, the unexpanded `ls` glob exits 2, and under
   `set -euo pipefail` the whole pipeline aborts *after a successful dump* — cron logs a
   failure for a run that actually worked, and the `backup ok` echo never prints. Fixed
   with `compgen -G` + `mapfile` (yields nothing on no-match) and rotation moved before
   the dump.
2. **Overlapping runs clobber/delete live dumps.** Two runs in the same second both derive
   `gw_system_${STAMP}.dump` (second-resolution stamp): the second overwrites the first,
   and post-dump rotation could delete a still-in-flight file. Fixed with `exec 9>lock;
   flock 9` serializing the whole run.
3. **Exit-0 was not proof of restorability, and retention died with a failed dump.** If the
   dump failed, rotation (post-dump) silently didn't run — stale backups accumulated
   forever. Rotation-first fixes retention; dump verification is now `! -s` + `pg_restore
   -l` with FAILED logging, exit 1.
4. **Credentials footgun (found by review for prod).** Script defaults `postgres/postgres`
   fit the dev DB but not vanilla Ubuntu Postgres (peer auth, passwordless superuser).
   Not a runtime bug in dev — a go-live hazard. Documented and solved with a dedicated
   `gw_backup` role and a root-only env file the cron sources.

## Test results
- `HostBackupTest` — 4/4 passed, 21 assertions.
- Full suite: **463/463 passed, 1648 assertions** (`php -d memory_limit=512M
  vendor/phpunit/phpunit`; 459 before + the 4 new).
- `php -l` clean; `pint` applied (EOF blank line only).
- `bash -n` **NOT run** — no bash on this Windows host (git-bash not on PATH). The bash
  syntax is exercised instead by the real drill below (running it is the stronger check).
- **Live restore drill (proof):** `pg_dump -Fc` → 70,795-byte dump; `pg_restore -l` OK;
  `pg_restore` into scratch DB → `service_connections=15, invoices=9`; scratch DB
  dropped; temp dump removed. All on the local Postgres 18 using the exact creds/flags of
  the shipped script.

## Known gaps / next step
- The Linux host install (cron copy, `gw_backup` role, first cron-fired dump + drill) is
  an Infra-phase action on the host you choose — the artifact is proven local, not live
  anywhere.
- `bash -n`/ShellCheck of `backup.sh`/`restore-drill.sh` should be run on the first Linux
  box (CI or the host) — no bash available here.
- `graphify . --update` skipped: deploy scripts + docs + one PHP test only, no shared-code
  structural change (same call as the queue-worker session).

## Commit
Not committed (needs explicit approval). Suggest this bundle as one unit: backup.sh +
restore-drill.sh + cron line + runbook §5 + README + HostBackupTest + ARCHITECTURE
checkbox + this summary.