#!/usr/bin/env bash
# GW-System — daily Postgres backup (host side, Linux).
#
# Scheduled from deploy/linux/cron-gw-system at 02:30 AM (before the 03:05
# billing run, outside the 06:00 reconcile) so a dump never lands mid-billing.
# Produces one rotating pg_dump (custom format) per day; keeps BACKUP_KEEP.
#
# Values default to the same Postgres the app uses. The app's own .env is not
# shell-readable here, so set these to match it if they differ (or export them
# from the cron line / a secrets file the app doesn't read).
#
# Production hosts MUST NOT use the dev-only defaults below. Create a
# dedicated backup role and pass real credentials in the script's env (e.g.
# from a root-only env file the cron line sources):
#
#     sudo -u postgres psql -c "CREATE ROLE gw_backup LOGIN PASSWORD '<secret>'"
#     sudo -u postgres psql -d gw_system -c \
#         "GRANT CONNECT ON DATABASE gw_system TO gw_backup; \
#          GRANT USAGE ON SCHEMA public TO gw_backup; \
#          GRANT SELECT ON ALL TABLES IN SCHEMA public TO gw_backup; \
#          ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO gw_backup;"
#
#     # cron line wrapper: . /etc/gw-backup.env  (root-only, chmod 600)
#     # gw-backup.env: export DB_USER=gw_backup  DB_PASSWORD='<secret>'
#
# Encryption of the dump file itself is NOT applied here; pg_dump data is
# readable by anyone with the file. Put the backup dir off-box or encrypt if
# sensitive.
set -euo pipefail

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5432}"
DB_NAME="${DB_NAME:-gw_system}"
DB_USER="${DB_USER:-postgres}"
DB_PASSWORD="${DB_PASSWORD:-postgres}"

OUT_DIR="${BACKUP_DIR:-/var/backups/gw-system}"
BACKUP_KEEP="${BACKUP_KEEP:-15}"
STAMP="$(date +%Y%m%d-%H%M%S)"
DUMP="$OUT_DIR/gw_system_${STAMP}.dump"

# Binary lookup; PG_BIN overrides for nonstandard installs
# (e.g. PG_BIN=/usr/lib/postgresql/18/bin).
PG_BIN="${PG_BIN:-}"
PG_DUMP="${PG_BIN:+$PG_BIN/}pg_dump"
PG_RESTORE="${PG_BIN:+$PG_BIN/}pg_restore"

command -v "$PG_DUMP" >/dev/null 2>&1 || { echo "backup FAILED: pg_dump not found (set PG_BIN)" >&2; exit 1; }

mkdir -p "$OUT_DIR"

# Serialize runs: a manual run overlapping the cron run would otherwise both
# derive the same second-resolution filename and clobber each other. flock
# waits, so the loser dumps right after — dumps never overlap.
exec 9>"$OUT_DIR/.backup.lock"
flock 9

# Rotate BEFORE dumping: retention is enforced even when the dump itself
# fails. compgen -G yields nothing (no error) when no dumps exist yet, so a
# first-ever run can't abort under `set -e`.
mapfile -t DUMPS < <(compgen -G "$OUT_DIR/gw_system_*.dump" | sort -r)
if (( ${#DUMPS[@]} > BACKUP_KEEP )); then
    for f in "${DUMPS[@]:BACKUP_KEEP}"; do
        rm -f "$f"
    done
fi

PGPASSWORD="$DB_PASSWORD" "$PG_DUMP" \
    -h "$DB_HOST" \
    -p "$DB_PORT" \
    -U "$DB_USER" \
    -Fc \
    "$DB_NAME" > "$DUMP"

# An exit-0 dump is not yet a good backup: prove the file is a readable
# Postgres archive before calling the run successful.
if [[ ! -s "$DUMP" ]]; then
    echo "backup FAILED: $DUMP is empty" >&2
    exit 1
fi
if ! "$PG_RESTORE" -l "$DUMP" >/dev/null 2>&1; then
    echo "backup FAILED: $DUMP does not parse as a Postgres archive" >&2
    exit 1
fi

echo "$(date +%Y-%m-%dT%H:%M:%S) backup ok: $DUMP ($(du -h "$DUMP" | cut -f1))"

exit 0
