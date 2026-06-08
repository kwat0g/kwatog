#!/usr/bin/env bash
# db-backup.sh — dump the OGAMI Postgres database to a timestamped gzipped file.
#
# Usage: BACKUP_DIR=/backups DB_USERNAME=ogami DB_PASSWORD=... \
#          DB_DATABASE=ogami DB_HOST=db DB_PORT=5432 \
#          ./scripts/db-backup.sh
#
# Requires `pg_dump` on PATH. From the host, the easiest invocation is via the
# Makefile target `make backup`, which execs into the db container where
# pg_dump is already installed.
#
# Retention: keeps the newest BACKUP_KEEP (default 14) backups; older files
# are deleted.

set -euo pipefail

: "${DB_HOST:=db}"
: "${DB_PORT:=5432}"
: "${DB_USERNAME:?DB_USERNAME required}"
: "${DB_PASSWORD:?DB_PASSWORD required}"
: "${DB_DATABASE:?DB_DATABASE required}"
: "${BACKUP_DIR:=/backups}"
: "${BACKUP_KEEP:=14}"

mkdir -p "${BACKUP_DIR}"

TS="$(date +%Y%m%d-%H%M%S)"
OUT="${BACKUP_DIR}/ogami-${TS}.sql.gz"

# PGPASSWORD is the cleanest non-interactive password path for pg_dump.
PGPASSWORD="${DB_PASSWORD}" pg_dump \
    --host="${DB_HOST}" \
    --port="${DB_PORT}" \
    --username="${DB_USERNAME}" \
    --dbname="${DB_DATABASE}" \
    --format=plain \
    --no-owner \
    --no-privileges \
    | gzip > "${OUT}"

# Sanity: a successful dump is never empty.
if [ ! -s "${OUT}" ]; then
    echo "FATAL: backup file ${OUT} is empty" >&2
    rm -f "${OUT}"
    exit 1
fi

SIZE="$(du -h "${OUT}" | cut -f1)"
echo "backup written: ${OUT} (${SIZE})"

# Retention: list backups newest-first, skip the first BACKUP_KEEP, delete the rest.
if [ "${BACKUP_KEEP}" -gt 0 ]; then
    # shellcheck disable=SC2012  # ls is fine for predictable filenames
    ls -1t "${BACKUP_DIR}"/ogami-*.sql.gz 2>/dev/null \
        | tail -n "+$((BACKUP_KEEP + 1))" \
        | xargs -r rm -f --
fi
