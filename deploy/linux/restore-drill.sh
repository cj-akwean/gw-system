#!/usr/bin/env bash
# GW-System — restore drill (host side, Linux).
#
# Proves a backup is actually restorable: restores a dump into a scratch
# database, sanity-checks it, then drops the scratch DB. Money-data rule: a
# backup that was never restored is a rumor. Run at least once before
# go-live (see docs/deployment-runbook.md section 5) and after any change to
# the backup process.
#
# Usage:
#     deploy/linux/restore-drill.sh                 # newest dump in BACKUP_DIR
#     deploy/linux/restore-drill.sh /path/to/x.dump # a specific dump
#
# Safe by construction: only ever writes to a database named
# gw_drill_<timestamp>; never touches the live database. Requires the same
# credentials as backup.sh (DB_USER/DB_PASSWORD/DB_HOST/DB_PORT).
set -euo pipefail

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5432}"
DB_USER="${DB_USER:-postgres}"
DB_PASSWORD="${DB_PASSWORD:-postgres}"
OUT_DIR="${BACKUP_DIR:-/var/backups/gw-system}"

PG_BIN="${PG_BIN:-}"
PSQL="${PG_BIN:+$PG_BIN/}psql"
PG_RESTORE="${PG_BIN:+$PG_BIN/}pg_restore"

for bin in "$PSQL" "$PG_RESTORE"; do
    command -v "$bin" >/dev/null 2>&1 || { echo "drill FAILED: $bin not found (set PG_BIN)" >&2; exit 1; }
done

DUMP="${1:-}"
if [[ -z "$DUMP" ]]; then
    DUMP="$(compgen -G "$OUT_DIR/gw_system_*.dump" | sort -r | head -n1)"
fi
[[ -n "$DUMP" && -f "$DUMP" ]] || { echo "drill FAILED: no dump given and none found in $OUT_DIR" >&2; exit 1; }

SCRATCH="gw_drill_$(date +%Y%m%d%H%M%S)"
export PGPASSWORD="$DB_PASSWORD"

cleanup() {
    "$PSQL" -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d postgres \
        -c "DROP DATABASE IF EXISTS \"$SCRATCH\"" >/dev/null 2>&1 || true
}
trap cleanup EXIT

"$PSQL" -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d postgres \
    -v ON_ERROR_STOP=1 -c "CREATE DATABASE \"$SCRATCH\"" >/dev/null

"$PG_RESTORE" -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$SCRATCH" "$DUMP" >/dev/null

CONNECTIONS="$("$PSQL" -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$SCRATCH" -tAc "select count(*) from service_connections")"
INVOICES="$("$PSQL" -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$SCRATCH" -tAc "select count(*) from invoices")"

echo "drill ok: restored $DUMP into $SCRATCH (service_connections=$CONNECTIONS, invoices=$INVOICES)"
exit 0