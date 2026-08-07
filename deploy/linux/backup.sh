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

mkdir -p "$OUT_DIR"

PGPASSWORD="$DB_PASSWORD" pg_dump \
    -h "$DB_HOST" \
    -p "$DB_PORT" \
    -U "$DB_USER" \
    -Fc \
    "$DB_NAME" > "$OUT_DIR/gw_system_${STAMP}.dump"

# Rotate: keep the newest BACKUP_KEEP dumps, delete the rest.
ls -1t "$OUT_DIR"/gw_system_*.dump 2>/dev/null \
    | tail -n +$((BACKUP_KEEP + 1)) \
    | xargs -r rm -f

echo "$(date +%Y-%m-%dT%H:%M:%S) backup ok: $OUT_DIR/gw_system_${STAMP}.dump"

exit 0