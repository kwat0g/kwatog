#!/usr/bin/env bash
# db-restore.sh — restore an OGAMI Postgres database from a gzipped pg_dump file.
#
# DESTRUCTIVE: drops and recreates the target database. Requires --yes.
#
# Usage: DB_USERNAME=ogami DB_PASSWORD=... DB_DATABASE=ogami DB_HOST=db DB_PORT=5432 \
#          ./scripts/db-restore.sh --yes /backups/ogami-20260609-103000.sql.gz
#
# From the host: `make restore FILE=/backups/ogami-20260609-103000.sql.gz`.

set -euo pipefail

CONFIRM=0
FILE=""
for arg in "$@"; do
    case "${arg}" in
        --yes)  CONFIRM=1 ;;
        --help|-h)
            sed -n '2,11p' "$0"
            exit 0
            ;;
        *)      FILE="${arg}" ;;
    esac
done

if [ -z "${FILE}" ]; then
    echo "ERROR: dump file path required as a positional arg" >&2
    exit 2
fi
if [ ! -f "${FILE}" ]; then
    echo "ERROR: dump file not found: ${FILE}" >&2
    exit 2
fi
if [ "${CONFIRM}" -ne 1 ]; then
    echo "ERROR: refusing to restore without --yes (destructive: drops and recreates ${DB_DATABASE:-the target DB})" >&2
    exit 2
fi

: "${DB_HOST:=db}"
: "${DB_PORT:=5432}"
: "${DB_USERNAME:?DB_USERNAME required}"
: "${DB_PASSWORD:?DB_PASSWORD required}"
: "${DB_DATABASE:?DB_DATABASE required}"

export PGPASSWORD="${DB_PASSWORD}"

# Connect to the maintenance DB (`postgres`) to drop+create the target.
PSQL_ADMIN=(psql --host="${DB_HOST}" --port="${DB_PORT}" --username="${DB_USERNAME}" --dbname=postgres -v ON_ERROR_STOP=1)

echo "==> terminating active connections to ${DB_DATABASE}"
"${PSQL_ADMIN[@]}" -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '${DB_DATABASE}' AND pid <> pg_backend_pid();" >/dev/null

echo "==> dropping and recreating ${DB_DATABASE}"
"${PSQL_ADMIN[@]}" -c "DROP DATABASE IF EXISTS \"${DB_DATABASE}\";"
"${PSQL_ADMIN[@]}" -c "CREATE DATABASE \"${DB_DATABASE}\" OWNER \"${DB_USERNAME}\";"

echo "==> restoring from ${FILE}"
gunzip -c "${FILE}" \
    | psql --host="${DB_HOST}" --port="${DB_PORT}" --username="${DB_USERNAME}" --dbname="${DB_DATABASE}" -v ON_ERROR_STOP=1 >/dev/null

echo "restore complete."
