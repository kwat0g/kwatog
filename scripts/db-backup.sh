#!/usr/bin/env bash
# db-backup.sh — dump the OGAMI Postgres database to a timestamped gzipped file.
#
# Usage: BACKUP_DIR=/backups DB_USERNAME=ogami DB_PASSWORD=... \
#          DB_DATABASE=ogami DB_HOST=db DB_PORT=5432 \
#          ./scripts/db-backup.sh
#
# Requires a `pg_dump` on PATH whose major matches the server's — the dump is
# rejected below if it does not, because a newer pg_dump writes SQL the server
# cannot restore. Both the api image (postgresql16-client) and the db container
# satisfy this; `make backup` execs into the db container.
#
# Retention: keeps the newest BACKUP_KEEP (default 14) backups; older files
# are deleted.

set -euo pipefail

# Version helpers are inlined rather than sourced: `make backup` / `prod-backup`
# `docker cp` this file alone into a container and run it from /tmp, so it has
# to stand on its own.
#
# `head` closes the pipe early, so gzip takes SIGPIPE under `set -o pipefail`;
# swallow that rather than aborting.
dump_header() {
    gzip -cd "$1" 2>/dev/null | head -40 || true
}

: "${DB_HOST:=db}"
: "${DB_PORT:=5432}"
: "${DB_USERNAME:?DB_USERNAME required}"
: "${DB_PASSWORD:?DB_PASSWORD required}"
: "${DB_DATABASE:?DB_DATABASE required}"
: "${BACKUP_DIR:=/backups}"
: "${BACKUP_KEEP:=14}"

case "${BACKUP_KEEP}" in
    ''|*[!0-9]*)
        echo "ERROR: BACKUP_KEEP must be a non-negative integer" >&2
        exit 2
        ;;
esac

mkdir -p "${BACKUP_DIR}"

TS="$(date +%Y%m%d-%H%M%S)"
OUT="${BACKUP_DIR}/ogami-${TS}.sql.gz"
TMP="$(mktemp "${BACKUP_DIR}/.ogami-${TS}.XXXXXX")"

cleanup() {
    if [ -n "${TMP:-}" ]; then
        rm -f "${TMP}"
    fi
}
trap cleanup EXIT

# PGPASSWORD is the cleanest non-interactive password path for pg_dump.
if ! PGPASSWORD="${DB_PASSWORD}" pg_dump \
        --host="${DB_HOST}" \
        --port="${DB_PORT}" \
        --username="${DB_USERNAME}" \
        --dbname="${DB_DATABASE}" \
        --format=plain \
        --no-owner \
        --no-privileges \
        | gzip > "${TMP}"; then
    echo "ERROR: pg_dump failed; no backup was published" >&2
    exit 1
fi

# Sanity: validate before atomically publishing the final filename. A killed
# process can leave only the hidden temp file, never a plausible backup name.
if [ ! -s "${TMP}" ] || ! gzip -t "${TMP}"; then
    echo "FATAL: backup archive is empty or corrupt; no backup was published" >&2
    exit 1
fi

# Integrity is not restorability. `gzip -t` above passes for a dump written by a
# pg_dump newer than the server, which the server then refuses on restore (v18
# writes `SET transaction_timeout = 0`, which a v16 server rejects under
# ON_ERROR_STOP) — so the skew has to be caught here, before the file earns a
# plausible backup name and gets reported as a good backup. The dump records
# both versions in its own header; compare those.
HEADER="$(dump_header "${TMP}")"
DUMP_CLIENT_MAJOR="$(printf '%s\n' "${HEADER}" | sed -n 's/^-- Dumped by pg_dump version \([0-9]\{1,\}\).*/\1/p' | head -1)"
DUMP_SERVER_MAJOR="$(printf '%s\n' "${HEADER}" | sed -n 's/^-- Dumped from database version \([0-9]\{1,\}\).*/\1/p' | head -1)"

if [ -z "${DUMP_CLIENT_MAJOR}" ] || [ -z "${DUMP_SERVER_MAJOR}" ]; then
    echo "FATAL: dump has no pg_dump/server version header; no backup was published" >&2
    exit 1
fi

if [ "${DUMP_CLIENT_MAJOR}" != "${DUMP_SERVER_MAJOR}" ]; then
    echo "FATAL: pg_dump ${DUMP_CLIENT_MAJOR} dumped a PostgreSQL ${DUMP_SERVER_MAJOR} server;" \
         "the dump would fail to restore. Install postgresql${DUMP_SERVER_MAJOR}-client" \
         "wherever this runs. No backup was published." >&2
    exit 1
fi

mv -f "${TMP}" "${OUT}"
TMP=""

SIZE="$(du -h "${OUT}" | cut -f1)"
CHECKSUM="$(sha256sum "${OUT}" | awk '{print $1}')"
echo "backup written: ${OUT} (${SIZE})"

# Phase 5b — optional off-site upload.
#
# If BACKUP_S3_BUCKET is set (e.g. 's3://ogami-backups') the dump is also
# uploaded with `aws s3 cp`. A configured off-site target is a requirement:
# missing tooling or a failed upload is reported non-zero rather than allowing
# the scheduler to claim that the backup process finished.
#
# Use AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY / AWS_DEFAULT_REGION env
# vars (already set via .env on the prod box) for authentication.
if [ -n "${BACKUP_S3_BUCKET:-}" ]; then
    if ! command -v aws >/dev/null 2>&1; then
        echo "ERROR: BACKUP_S3_BUCKET is set but 'aws' CLI is not installed" >&2
        exit 2
    else
        PREFIX="${BACKUP_S3_PREFIX:-}"
        if [ -n "${PREFIX}" ] && [ "${PREFIX%/}" = "${PREFIX}" ]; then
            PREFIX="${PREFIX}/"
        fi
        REMOTE="${BACKUP_S3_BUCKET%/}/${PREFIX}$(basename "${OUT}")"
        echo "uploading to ${REMOTE}"
        if aws s3 cp "${OUT}" "${REMOTE}" \
            --metadata "sha256=${CHECKSUM}" \
            --only-show-errors; then
            echo "off-site copy ok"
        else
            echo "ERROR: off-site upload failed for ${OUT}" >&2
            exit 2
        fi
    fi
fi

# Retention: list backups newest-first, skip the first BACKUP_KEEP, delete the rest.
if [ "${BACKUP_KEEP}" -gt 0 ]; then
    # shellcheck disable=SC2012  # ls is fine for predictable filenames
    ls -1t "${BACKUP_DIR}"/ogami-*.sql.gz 2>/dev/null \
        | tail -n "+$((BACKUP_KEEP + 1))" \
        | xargs -r rm -f --
fi
