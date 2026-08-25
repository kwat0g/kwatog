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

# Inlined rather than sourced: `make restore` `docker cp`s this file alone into
# the db container and runs it from /tmp, so it has to stand on its own.
# `head` closes the pipe early, so gzip takes SIGPIPE under `set -o pipefail`;
# swallow that rather than aborting.
dump_client_major() {
    gzip -cd "$1" 2>/dev/null | head -40 \
        | sed -n 's/^-- Dumped by pg_dump version \([0-9]\{1,\}\).*/\1/p' \
        | head -1 || true
}

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

# DB_DATABASE is deployment configuration, not a user input field. Still
# validate it before interpolating it into the maintenance SQL so a malformed
# server environment can never turn this destructive helper into arbitrary SQL.
case "${DB_DATABASE}" in
    ''|*[!a-zA-Z0-9_-]*)
        echo "ERROR: DB_DATABASE contains unsupported characters" >&2
        exit 2
        ;;
esac

export PGPASSWORD="${DB_PASSWORD}"

# Connect to the maintenance DB (`postgres`) to drop+create the target.
PSQL_ADMIN=(psql --host="${DB_HOST}" --port="${DB_PORT}" --username="${DB_USERNAME}" --dbname=postgres -v ON_ERROR_STOP=1)

# Version check BEFORE anything destructive. A dump from a newer major carries
# directives this server rejects; ON_ERROR_STOP would then abort partway through
# the restore below — after the drop, with the old database already gone. Older
# dumps into a newer server are supported, so only a newer dump is refused.
ARCHIVE_MAJOR="$(dump_client_major "${FILE}")"
SERVER_MAJOR="$("${PSQL_ADMIN[@]}" -tAc 'SHOW server_version' 2>/dev/null | sed -n 's/^\([0-9]\{1,\}\).*/\1/p' | head -1 || true)"

if [ -z "${ARCHIVE_MAJOR}" ] || [ -z "${SERVER_MAJOR}" ]; then
    echo "ERROR: could not read dump major (${ARCHIVE_MAJOR:-unknown}) or server major (${SERVER_MAJOR:-unknown}); refusing to restore" >&2
    exit 2
fi

if [ "${ARCHIVE_MAJOR}" -gt "${SERVER_MAJOR}" ]; then
    echo "ERROR: dump was written by pg_dump ${ARCHIVE_MAJOR} but this server is PostgreSQL ${SERVER_MAJOR};" \
         "it would fail partway through, after the drop. Restore it on a ${ARCHIVE_MAJOR} server," \
         "or re-dump with postgresql${SERVER_MAJOR}-client." >&2
    exit 2
fi

echo "==> terminating active connections to ${DB_DATABASE}"
# DB_DATABASE is interpolated directly, as in the DROP/CREATE below: psql does
# not expand `:'var'` inside a -c string (it goes to the server verbatim, which
# is a syntax error), and the character validation above is what makes the
# interpolation safe.
"${PSQL_ADMIN[@]}" \
    -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '${DB_DATABASE}' AND pid <> pg_backend_pid();" >/dev/null

echo "==> dropping and recreating ${DB_DATABASE}"
"${PSQL_ADMIN[@]}" -c "DROP DATABASE IF EXISTS \"${DB_DATABASE}\";"
"${PSQL_ADMIN[@]}" -c "CREATE DATABASE \"${DB_DATABASE}\" OWNER \"${DB_USERNAME}\";"

echo "==> restoring from ${FILE}"
gunzip -c "${FILE}" \
    | psql --host="${DB_HOST}" --port="${DB_PORT}" --username="${DB_USERNAME}" --dbname="${DB_DATABASE}" -v ON_ERROR_STOP=1 >/dev/null

echo "restore complete."
